<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * Synergy Wholesale domain registrar adapter.
 *
 * @see https://synergywholesale.com/support-centre/using-the-synergy-wholesale-api/
 */

class Registrar_Adapter_Synergy extends Registrar_AdapterAbstract
{
    private const WSDL = 'https://api.synergywholesale.com/server.php?wsdl';

    /** @var array<string, string|null> */
    public array $config = [
        'reseller_id' => null,
        'api_key' => null,
    ];

    private ?SoapClient $client = null;

    /** @var array<string, string> */
    private const COUNTRY_CALLING_CODES = [
        'AU' => '61',
        'NZ' => '64',
        'US' => '1',
        'CA' => '1',
        'GB' => '44',
        'UK' => '44',
        'DE' => '49',
        'IN' => '91',
        'PK' => '92',
        'SG' => '65',
        'IE' => '353',
    ];

    /** @var array<string, string> */
    private const AU_STATES = [
        'ACT' => 'ACT',
        'AUSTRALIAN CAPITAL TERRITORY' => 'ACT',
        'NSW' => 'NSW',
        'NEW SOUTH WALES' => 'NSW',
        'NT' => 'NT',
        'NORTHERN TERRITORY' => 'NT',
        'QLD' => 'QLD',
        'QUEENSLAND' => 'QLD',
        'SA' => 'SA',
        'SOUTH AUSTRALIA' => 'SA',
        'TAS' => 'TAS',
        'TASMANIA' => 'TAS',
        'VIC' => 'VIC',
        'VICTORIA' => 'VIC',
        'WA' => 'WA',
        'WESTERN AUSTRALIA' => 'WA',
    ];

    public function __construct(array $options)
    {
        if (!extension_loaded('soap')) {
            throw new Registrar_Exception('The PHP SOAP extension is required for the Synergy Wholesale registrar.');
        }

        if (empty($options['reseller_id'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Synergy Wholesale', ':missing' => 'Reseller ID'], 3001);
        }
        if (empty($options['api_key'])) {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Synergy Wholesale', ':missing' => 'API Key'], 3001);
        }

        $this->config['reseller_id'] = $options['reseller_id'];
        $this->config['api_key'] = $options['api_key'];
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Registers and manages domains via Synergy Wholesale.',
            'form' => [
                'reseller_id' => [
                    'text', [
                        'label' => 'Reseller ID',
                        'required' => true,
                    ],
                ],
                'api_key' => [
                    'password', [
                        'label' => 'API Key',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    public function getTlds(): array
    {
        return [];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        // Synergy checkDomain command must be create|transfer|renew|restore (not "register").
        $result = $this->call('checkDomain', [
            'domainName' => $domain->getName(),
            'command' => 'create',
        ], strict: false);

        return str_starts_with(strtoupper((string) ($result->status ?? '')), 'AVAILABLE');
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $result = $this->call('checkDomain', [
            'domainName' => $domain->getName(),
            'command' => 'transfer',
        ], strict: false);

        $status = strtoupper((string) ($result->status ?? ''));

        return str_starts_with($status, 'AVAILABLE') || $status === 'OK';
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $nameservers = $this->nameservers($domain);
        if (count($nameservers) < 2) {
            throw new Registrar_Exception('At least two nameservers are required to register a domain with Synergy Wholesale.');
        }

        // domainRegister expects unprefixed contact fields (matches Synergy API / middleware).
        $params = [
            'domainName' => $domain->getName(),
            'years' => $domain->getRegistrationPeriod() ?: 1,
            'nameServers' => $nameservers,
            'idProtect' => (bool) $domain->getPrivacyEnabled(),
            'specialConditionsAgree' => true,
            ...$this->contactFields($domain->getContactRegistrar(), $domain),
        ];

        $this->call('domainRegister', $params);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        if (!$domain->getEpp()) {
            throw new Registrar_Exception('An EPP/auth code is required to transfer a domain with Synergy Wholesale.');
        }

        $this->call('transferDomain', [
            'domainName' => $domain->getName(),
            'authInfo' => $domain->getEpp(),
            'doRenewal' => false,
            'idProtect' => (bool) $domain->getPrivacyEnabled(),
            ...$this->contactFields($domain->getContactRegistrar(), $domain),
        ]);

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->call('renewDomain', [
            'domainName' => $domain->getName(),
            'years' => $domain->getRegistrationPeriod() ?: 1,
        ]);

        return true;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $nameservers = $this->nameservers($domain);
        if (count($nameservers) < 2) {
            throw new Registrar_Exception('At least two nameservers are required to update nameservers with Synergy Wholesale.');
        }

        $this->call('updateNameServers', [
            'domainName' => $domain->getName(),
            'nameServers' => $nameservers,
            'dnsConfigType' => 1,
        ]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $params = ['domainName' => $domain->getName()];

        foreach ([
            'registrant' => $domain->getContactRegistrar(),
            'admin' => $domain->getContactAdmin() ?: $domain->getContactRegistrar(),
            'technical' => $domain->getContactTech() ?: $domain->getContactRegistrar(),
            'billing' => $domain->getContactBilling() ?: $domain->getContactRegistrar(),
        ] as $role => $contact) {
            foreach ($this->contactFields($contact, $domain) as $key => $value) {
                $params[$role . '_' . $key] = $value;
            }
        }

        $this->call('updateContact', $params);

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $result = $this->call('domainInfo', ['domainName' => $domain->getName()]);

        $domain->setExpirationTime($this->toTimestamp($result->domain_expiry ?? null));
        $domain->setEpp((string) ($result->domainPassword ?? ''));
        $domain->setPrivacyEnabled(strtolower((string) ($result->idProtect ?? '')) === 'enabled');
        $domain->setLocked($this->isTransferLocked((string) ($result->domain_status ?? '')));

        $nameservers = $result->nameServers ?? [];
        if (!is_array($nameservers)) {
            $nameservers = [$nameservers];
        }
        foreach (array_values($nameservers) as $index => $nameserver) {
            $setter = 'setNs' . ($index + 1);
            if (method_exists($domain, $setter)) {
                $domain->$setter(strtolower((string) $nameserver));
            }
        }

        return $domain;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $details = $this->getDomainDetails($domain);
        $epp = (string) $details->getEpp();
        if ($epp === '') {
            throw new Registrar_Exception('Synergy Wholesale did not return an EPP/auth code for this domain.');
        }

        return $epp;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->call('lockDomain', ['domainName' => $domain->getName()]);
        $domain->setLocked(true);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->call('unlockDomain', ['domainName' => $domain->getName()]);
        $domain->setLocked(false);

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->call('enableIDProtection', ['domainName' => $domain->getName()]);
        $domain->setPrivacyEnabled(true);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->call('disableIDProtection', ['domainName' => $domain->getName()]);
        $domain->setPrivacyEnabled(false);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): never
    {
        throw new Registrar_Exception(':type: does not support :action:', [':type:' => 'Synergy Wholesale', ':action:' => __trans('deleting domains')]);
    }

    /**
     * List DNS zone records for a domain hosted on Synergy DNS.
     *
     * @return list<array{id: string, hostname: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    public function listDnsZone(string $domainName): array
    {
        $result = $this->call('listDNSZone', ['domainName' => $domainName], strict: false);
        $status = strtoupper((string) ($result->status ?? ''));
        if ($status !== '' && !preg_match('/^OK/', $status)) {
            $message = (string) ($result->errorMessage ?? $result->statusDescription ?? $status);
            // Missing zone is not fatal for callers that will create one.
            if (str_contains(strtolower($message), 'not found') || str_contains(strtolower($status), 'not_found')) {
                return [];
            }
            throw new Registrar_Exception(':type registrar error: :error', [':type' => 'Synergy Wholesale', ':error' => $message]);
        }

        return $this->normaliseDnsRecords($result->records ?? null);
    }

    public function addDnsZone(string $domainName): bool
    {
        $this->call('addDNSZone', ['domainName' => $domainName]);

        return true;
    }

    /**
     * Ensure a Synergy DNS zone exists (list or create).
     */
    public function ensureDnsZone(string $domainName): bool
    {
        $result = $this->call('listDNSZone', ['domainName' => $domainName], strict: false);
        $status = strtoupper((string) ($result->status ?? ''));
        if (preg_match('/^OK/', $status)) {
            return true;
        }

        return $this->addDnsZone($domainName);
    }

    /**
     * @return array{id: string|null, status: string}
     */
    public function addDnsRecord(
        string $domainName,
        string $hostname,
        string $type,
        string $content,
        int $ttl = 3600,
        ?int $priority = null,
    ): array {
        $result = $this->call('addDNSRecord', [
            'domainName' => $domainName,
            'recordName' => $hostname,
            'recordType' => strtoupper($type),
            'recordContent' => $content,
            'recordTTL' => $ttl,
            'recordPrio' => $priority ?? 0,
        ]);

        return [
            'id' => isset($result->id) ? (string) $result->id : (isset($result->recordID) ? (string) $result->recordID : null),
            'status' => (string) ($result->status ?? 'OK'),
        ];
    }

    public function updateDnsRecord(
        string $domainName,
        string $recordId,
        string $hostname,
        string $type,
        string $content,
        int $ttl = 3600,
        ?int $priority = null,
    ): bool {
        $this->call('updateDNSRecord', [
            'domainName' => $domainName,
            'recordID' => $recordId,
            'recordName' => $hostname,
            'recordType' => strtoupper($type),
            'recordContent' => $content,
            'recordTTL' => (string) $ttl,
            'recordPrio' => $priority ?? 0,
        ]);

        return true;
    }

    public function deleteDnsRecord(string $domainName, string $recordId): bool
    {
        $this->call('deleteDNSRecord', [
            'domainName' => $domainName,
            'recordID' => $recordId,
        ]);

        return true;
    }

    /**
     * Create or update a DNS record matched by hostname + type (+ optional content prefix).
     *
     * @param array{content_prefix?: string, match_any_of_type?: bool} $match
     *
     * @return array{action: string, id: string|null}
     */
    public function upsertDnsRecord(
        string $domainName,
        string $hostname,
        string $type,
        string $content,
        int $ttl = 3600,
        ?int $priority = null,
        array $match = [],
    ): array {
        $type = strtoupper($type);
        $records = $this->listDnsZone($domainName);
        $existing = $this->findDnsRecord($records, $domainName, $hostname, $type, $match);

        if ($existing !== null) {
            $sameContent = trim((string) $existing['content'], '"') === trim($content, '"');
            $samePrio = (string) ($existing['priority'] ?? 0) === (string) ($priority ?? 0);
            if ($sameContent && $samePrio) {
                return ['action' => 'unchanged', 'id' => $existing['id']];
            }
            $this->updateDnsRecord($domainName, $existing['id'], $hostname, $type, $content, $ttl, $priority);

            return ['action' => 'updated', 'id' => $existing['id']];
        }

        $added = $this->addDnsRecord($domainName, $hostname, $type, $content, $ttl, $priority);

        return ['action' => 'added', 'id' => $added['id']];
    }

    /**
     * Apply standard web A/AAAA records for hosting (@ and www).
     * Removes apex ALIAS records that would conflict with an A record.
     *
     * @return array<string, array{action: string, id: string|null}>
     */
    public function applyWebDns(string $domainName, string $ipv4, ?string $ipv6 = null): array
    {
        $this->ensureDnsZone($domainName);
        $this->removeConflictingAlias($domainName);

        $out = [
            'apex_a' => $this->upsertDnsRecord($domainName, '@', 'A', $ipv4),
            'www_a' => $this->upsertDnsRecord($domainName, 'www', 'A', $ipv4),
        ];

        if ($ipv6 !== null && $ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $out['apex_aaaa'] = $this->upsertDnsRecord($domainName, '@', 'AAAA', $ipv6);
            $out['www_aaaa'] = $this->upsertDnsRecord($domainName, 'www', 'AAAA', $ipv6);
        }

        return $out;
    }

    /**
     * Apply mail-related DNS: mail A, MX, SPF, optional DKIM, DMARC,
     * plus client autodiscovery (autoconfig / autodiscover A + SRV)
     * and webmail A/AAAA (Roundcube catch-all on Pluto).
     *
     * OpenPanel generates DKIM keys locally; pass the public TXT value via $dkimTxt
     * when available. Without $dkimTxt, MX/SPF/DMARC/mail A are still applied.
     *
     * Synergy SOA RNAME (hostmaster@domain) cannot be changed via API — locked by
     * Synergy (“Unsupported record type” on SOA updates).
     *
     * SRV content format for Synergy: "weight port target." (priority via recordPrio).
     *
     * @return array<string, array{action: string, id: string|null}|null>
     */
    public function applyMailDns(
        string $domainName,
        string $ipv4,
        ?string $dkimTxt = null,
        ?string $mxHost = null,
        ?string $dmarc = null,
        ?string $ipv6 = null,
    ): array {
        $this->ensureDnsZone($domainName);
        $mxHost = $mxHost ?: ('mail.' . $domainName . '.');
        if (!str_ends_with($mxHost, '.')) {
            $mxHost .= '.';
        }
        $mailHost = rtrim($mxHost, '.');
        if (!str_contains($mailHost, '.')) {
            $mailHost = 'mail.' . $domainName;
        }
        $mailTarget = $mailHost . '.';
        $autodiscoverTarget = 'autodiscover.' . $domainName . '.';

        $hasIpv6 = $ipv6 !== null && $ipv6 !== '' && (bool) filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $spf = $hasIpv6
            ? sprintf('v=spf1 a mx ip4:%s ip6:%s ~all', $ipv4, $ipv6)
            : sprintf('v=spf1 a mx ip4:%s ~all', $ipv4);
        $dmarc = $dmarc ?: sprintf('v=DMARC1; p=none; rua=mailto:hostmaster@vioflare.com');

        $out = [
            'mail_a' => $this->upsertDnsRecord($domainName, 'mail', 'A', $ipv4),
            'webmail_a' => $this->upsertDnsRecord($domainName, 'webmail', 'A', $ipv4),
            'mx' => $this->upsertDnsRecord($domainName, '@', 'MX', $mxHost, 3600, 10, ['match_any_of_type' => true]),
            'spf' => $this->upsertDnsRecord($domainName, '@', 'TXT', $spf, 3600, null, ['content_prefix' => 'v=spf1']),
            'dmarc' => $this->upsertDnsRecord($domainName, '_dmarc', 'TXT', $dmarc, 3600, null, ['content_prefix' => 'v=DMARC1']),
            'dkim' => null,
            // Thunderbird / Apple Mail / Outlook discovery hostnames → Pluto
            'autoconfig_a' => $this->upsertDnsRecord($domainName, 'autoconfig', 'A', $ipv4),
            'autodiscover_a' => $this->upsertDnsRecord($domainName, 'autodiscover', 'A', $ipv4),
            // RFC 6186 / common client SRV hints (Synergy: weight port target)
            'srv_imaps' => $this->upsertDnsRecord($domainName, '_imaps._tcp', 'SRV', '1 993 ' . $mailTarget, 3600, 0),
            'srv_submission' => $this->upsertDnsRecord($domainName, '_submission._tcp', 'SRV', '1 587 ' . $mailTarget, 3600, 0),
            'srv_pop3s' => $this->upsertDnsRecord($domainName, '_pop3s._tcp', 'SRV', '1 995 ' . $mailTarget, 3600, 0),
            'srv_autodiscover' => $this->upsertDnsRecord($domainName, '_autodiscover._tcp', 'SRV', '1 443 ' . $autodiscoverTarget, 3600, 0),
        ];

        if ($hasIpv6) {
            $out['mail_aaaa'] = $this->upsertDnsRecord($domainName, 'mail', 'AAAA', $ipv6);
            $out['webmail_aaaa'] = $this->upsertDnsRecord($domainName, 'webmail', 'AAAA', $ipv6);
            $out['autoconfig_aaaa'] = $this->upsertDnsRecord($domainName, 'autoconfig', 'AAAA', $ipv6);
            $out['autodiscover_aaaa'] = $this->upsertDnsRecord($domainName, 'autodiscover', 'AAAA', $ipv6);
        }

        if ($dkimTxt !== null && $dkimTxt !== '') {
            $out['dkim'] = $this->upsertDnsRecord(
                $domainName,
                'mail._domainkey',
                'TXT',
                $dkimTxt,
                3600,
                null,
                ['content_prefix' => 'v=DKIM1'],
            );
        }

        return $out;
    }

    /**
     * Apply web + mail DNS records for a hosting activation on Synergy DNS.
     *
     * @param array{dkim_txt?: string, mx_host?: string, dmarc?: string, ipv6?: string} $options
     *
     * @return array{web: array, mail: array}
     */
    public function applyHostingDns(string $domainName, string $ipv4, array $options = []): array
    {
        $ipv6 = isset($options['ipv6']) && is_string($options['ipv6']) ? $options['ipv6'] : null;

        return [
            'web' => $this->applyWebDns($domainName, $ipv4, $ipv6),
            'mail' => $this->applyMailDns(
                $domainName,
                $ipv4,
                $options['dkim_txt'] ?? null,
                $options['mx_host'] ?? null,
                $options['dmarc'] ?? null,
                $ipv6,
            ),
        ];
    }

    /**
     * Synergy does not expose a documented public sandbox WSDL in this adapter.
     * Staging should use live credentials with disposable test domains only.
     */
    public function enableTestMode()
    {
        return parent::enableTestMode();
    }

    private function client(): SoapClient
    {
        if (!$this->client instanceof SoapClient) {
            $this->client = new SoapClient(self::WSDL, [
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_BOTH,
                'connection_timeout' => 30,
            ]);
        }

        return $this->client;
    }

    /**
     * Allow injecting a SoapClient in unit tests.
     */
    public function setSoapClient(SoapClient $client): void
    {
        $this->client = $client;
    }

    private function call(string $method, array $params = [], bool $strict = true): object
    {
        $request = [
            'resellerID' => $this->config['reseller_id'],
            'apiKey' => $this->config['api_key'],
            ...$params,
        ];

        try {
            // WSDL methods take a single complex-type argument (e.g. balanceQueryRequest).
            // Pass the fields directly — wrapping as ['request' => ...] fails PHP SoapClient encoding.
            $result = $this->client()->__soapCall($method, [$request]);
        } catch (Throwable $e) {
            $this->getLog()->error(sprintf('Synergy Wholesale API transport error on %s: %s', $method, $e->getMessage()));
            throw new Registrar_Exception('Failed to call :action with the :type registrar, check the error logs for further details', [':action' => $method, ':type' => 'Synergy Wholesale']);
        }

        $status = strtoupper((string) ($result->status ?? 'OK'));
        if ($strict && !preg_match('/^(OK|AVAILABLE)/', $status)) {
            $message = (string) ($result->errorMessage ?? $result->statusDescription ?? $status);
            $this->getLog()->error(sprintf('Synergy Wholesale API error on %s: %s', $method, $message));
            throw new Registrar_Exception(':type registrar error: :error', [':type' => 'Synergy Wholesale', ':error' => $message]);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function nameservers(Registrar_Domain $domain): array
    {
        return array_values(array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ], static fn ($ns) => is_string($ns) && $ns !== ''));
    }

    /**
     * Unprefixed contact fields used by domainRegister / transferDomain.
     * For updateContact, callers prefix with registrant_|admin_|technical_|billing_.
     *
     * @return array<string, mixed>
     */
    private function contactFields(?Registrar_Domain_Contact $contact, ?Registrar_Domain $domain = null): array
    {
        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('Domain contact details are required for Synergy Wholesale.');
        }

        $country = strtoupper((string) $contact->getCountry());
        $state = $this->normaliseState((string) $contact->getState(), $country, $domain);

        return [
            'firstname' => (string) $contact->getFirstName(),
            'lastname' => (string) $contact->getLastName(),
            'email' => (string) $contact->getEmail(),
            'phone' => $this->formatPhone($contact, $country),
            'address' => array_values(array_filter([
                (string) $contact->getAddress1(),
                (string) $contact->getAddress2(),
            ], static fn ($line) => $line !== '')),
            'suburb' => (string) $contact->getCity(),
            'state' => $state,
            'postcode' => (string) $contact->getZip(),
            'country' => $country,
            'organisation' => (string) $contact->getCompany(),
        ];
    }

    private function formatPhone(Registrar_Domain_Contact $contact, string $country): string
    {
        $phone = trim((string) $contact->getTel());
        if ($phone === '') {
            return '';
        }
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        $cc = preg_replace('/\D+/', '', (string) $contact->getTelCc()) ?: '';
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($cc === '') {
            $cc = self::COUNTRY_CALLING_CODES[$country] ?? '';
        }

        if ($cc === '61' && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if ($cc !== '') {
            return '+' . $cc . $digits;
        }

        return '+' . $digits;
    }

    private function normaliseState(string $state, string $country, ?Registrar_Domain $domain = null): string
    {
        $state = trim($state);
        $tld = strtolower((string) ($domain?->getTld() ?? ''));
        $isAu = $country === 'AU' || str_ends_with($tld, '.au');
        if (!$isAu || $state === '') {
            return $state;
        }

        $mapped = self::AU_STATES[strtoupper($state)] ?? null;
        if ($mapped === null) {
            throw new Registrar_Exception('A valid Australian state code is required (e.g. NSW, VIC, QLD).');
        }

        return $mapped;
    }

    private function isTransferLocked(string $status): bool
    {
        $status = strtolower($status);

        return str_contains($status, 'clienttransferprohibited')
            || str_contains($status, 'transferprohibited')
            || $status === 'locked';
    }

    private function toTimestamp(mixed $value): ?int
    {
        if (!$value) {
            return null;
        }
        $timestamp = strtotime((string) $value);

        return $timestamp ?: null;
    }

    /**
     * @return list<array{id: string, hostname: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    private function normaliseDnsRecords(mixed $records): array
    {
        if ($records === null) {
            return [];
        }
        if (!is_array($records)) {
            $items = $records->item ?? null;
            if ($items === null) {
                return [];
            }
            $records = is_array($items) ? $items : [$items];
        }

        $out = [];
        foreach ($records as $record) {
            if (!is_object($record) && !is_array($record)) {
                continue;
            }
            $get = static function (object|array $row, string $key): mixed {
                if (is_array($row)) {
                    return $row[$key] ?? null;
                }

                return $row->{$key} ?? null;
            };
            $ttlRaw = $get($record, 'ttl');
            $prioRaw = $get($record, 'prio');
            $out[] = [
                'id' => (string) ($get($record, 'id') ?? ''),
                'hostname' => (string) ($get($record, 'hostName') ?? ''),
                'type' => strtoupper((string) ($get($record, 'type') ?? '')),
                'content' => (string) ($get($record, 'content') ?? ''),
                'ttl' => $ttlRaw !== null && $ttlRaw !== '' ? (int) $ttlRaw : 3600,
                'priority' => ($prioRaw !== null && (string) $prioRaw !== '' && (string) $prioRaw !== '0')
                    ? (int) $prioRaw
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{id: string, hostname: string, type: string, content: string, ttl: int, priority: int|null}> $records
     * @param array{content_prefix?: string, match_any_of_type?: bool} $match
     *
     * @return array{id: string, hostname: string, type: string, content: string, ttl: int, priority: int|null}|null
     */
    private function findDnsRecord(array $records, string $domainName, string $hostname, string $type, array $match = []): ?array
    {
        $type = strtoupper($type);
        $prefix = isset($match['content_prefix']) ? strtolower((string) $match['content_prefix']) : null;
        $anyOfType = !empty($match['match_any_of_type']);

        foreach ($records as $record) {
            if (($record['type'] ?? '') !== $type) {
                continue;
            }
            if (!$anyOfType && !$this->hostMatchesDns((string) $record['hostname'], $hostname, $domainName)) {
                continue;
            }
            if ($prefix !== null && !str_starts_with(strtolower(trim((string) $record['content'], '"')), $prefix)) {
                continue;
            }

            return $record;
        }

        return null;
    }

    private function hostMatchesDns(string $hostname, string $want, string $domainName): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $want = strtolower(rtrim($want, '.'));
        $domainName = strtolower($domainName);

        if ($want === '@') {
            return in_array($hostname, ['@', $domainName, ''], true);
        }

        return in_array($hostname, [$want, $want . '.' . $domainName], true)
            || str_contains($hostname, $want);
    }

    private function removeConflictingAlias(string $domainName): void
    {
        foreach ($this->listDnsZone($domainName) as $record) {
            if (($record['type'] ?? '') !== 'ALIAS') {
                continue;
            }
            if (!$this->hostMatchesDns((string) $record['hostname'], '@', $domainName)) {
                continue;
            }
            if ($record['id'] === '') {
                continue;
            }
            $this->deleteDnsRecord($domainName, $record['id']);
            $this->getLog()->info(sprintf('Removed Synergy ALIAS for %s before applying A record', $domainName));
        }
    }
}
