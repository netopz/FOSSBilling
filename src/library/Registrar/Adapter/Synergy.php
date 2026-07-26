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
        $result = $this->call('checkDomain', [
            'domainName' => $domain->getName(),
            'command' => 'register',
        ]);

        return str_starts_with(strtoupper((string) ($result->status ?? '')), 'AVAILABLE');
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $result = $this->call('checkDomain', [
            'domainName' => $domain->getName(),
            'command' => 'transfer',
        ]);

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

    private function call(string $method, array $params = []): object
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
        if (!preg_match('/^(OK|AVAILABLE)/', $status)) {
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
}
