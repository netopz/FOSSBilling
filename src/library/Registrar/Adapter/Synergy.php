<?php

declare(strict_types=1);

class Registrar_Adapter_Synergy extends Registrar_AdapterAbstract
{
    private const WSDL = 'https://api.synergywholesale.com/server.php?wsdl';

    public array $config = [
        'reseller_id' => null,
        'api_key' => null,
    ];

    private ?SoapClient $client = null;

    public function __construct(array $options)
    {
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

        return str_starts_with(strtoupper((string) ($result->status ?? '')), 'AVAILABLE')
            || strtoupper((string) ($result->status ?? '')) === 'OK';
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $params = [
            'domainName' => $domain->getName(),
            'years' => $domain->getRegistrationPeriod() ?: 1,
            'nameServers' => $this->nameservers($domain),
            'idProtect' => (bool) $domain->getPrivacyEnabled(),
            'specialConditionsAgree' => true,
            ...$this->contactPayload($domain->getContactRegistrar()),
        ];

        $this->call('domainRegister', $params);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->call('transferDomain', [
            'domainName' => $domain->getName(),
            'authInfo' => $domain->getEpp(),
            'doRenewal' => false,
            'idProtect' => (bool) $domain->getPrivacyEnabled(),
            ...$this->contactPayload($domain->getContactRegistrar()),
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
        $this->call('updateNameServers', [
            'domainName' => $domain->getName(),
            'nameServers' => $this->nameservers($domain),
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
        ] as $prefix => $contact) {
            foreach ($this->contactPayload($contact) as $key => $value) {
                $params[$prefix . '_' . $key] = $value;
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
        $domain->setPrivacyEnabled(($result->idProtect ?? '') === 'Enabled');
        $domain->setLocked(($result->domain_status ?? '') === 'clientTransferProhibited');

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

        return (string) $details->getEpp();
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

    private function client(): SoapClient
    {
        if (!$this->client instanceof SoapClient) {
            $this->client = new SoapClient(self::WSDL, [
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_BOTH,
            ]);
        }

        return $this->client;
    }

    private function call(string $method, array $params = []): object
    {
        $request = [
            'resellerID' => $this->config['reseller_id'],
            'apiKey' => $this->config['api_key'],
            ...$params,
        ];

        try {
            $result = $this->client()->__soapCall($method, [['request' => $request]]);
        } catch (Throwable $e) {
            $this->getLog()->err('Synergy Wholesale API error: ' . $e->getMessage());
            throw new Registrar_Exception('Failed to call :action with the :type registrar, check the error logs for further details', [':action' => $method, ':type' => 'Synergy Wholesale']);
        }

        $status = strtoupper((string) ($result->status ?? 'OK'));
        if (!preg_match('/^(OK|AVAILABLE)/', $status)) {
            $message = (string) ($result->errorMessage ?? $result->statusDescription ?? $status);
            $this->getLog()->err('Synergy Wholesale API error: ' . $message);
            throw new Registrar_Exception(':type registrar error: :error', [':type' => 'Synergy Wholesale', ':error' => $message]);
        }

        return $result;
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        return array_values(array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]));
    }

    private function contactPayload(?Registrar_Domain_Contact $contact): array
    {
        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('Domain contact details are required for Synergy Wholesale.');
        }

        return [
            'firstname' => (string) $contact->getFirstName(),
            'lastname' => (string) $contact->getLastName(),
            'email' => (string) $contact->getEmail(),
            'phone' => $this->phone($contact),
            'address' => [
                (string) $contact->getAddress1(),
                (string) $contact->getAddress2(),
            ],
            'suburb' => (string) $contact->getCity(),
            'state' => (string) $contact->getState(),
            'postcode' => (string) $contact->getZip(),
            'country' => strtoupper((string) $contact->getCountry()),
            'organisation' => (string) $contact->getCompany(),
        ];
    }

    private function phone(Registrar_Domain_Contact $contact): string
    {
        $phone = (string) $contact->getTel();
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        $cc = preg_replace('/\D+/', '', (string) $contact->getTelCc());
        $digits = preg_replace('/\D+/', '', $phone);

        return $cc ? '+' . $cc . $digits : '+' . $digits;
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
