<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

function buildSynergyDomain(array $overrides = []): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())
        ->setFirstName($overrides['firstname'] ?? 'Jane')
        ->setLastName($overrides['lastname'] ?? 'Citizen')
        ->setEmail($overrides['email'] ?? 'jane@example.com')
        ->setTel($overrides['tel'] ?? '0412345678')
        ->setTelCc($overrides['tel_cc'] ?? '61')
        ->setAddress1($overrides['address1'] ?? '1 Example St')
        ->setAddress2($overrides['address2'] ?? '')
        ->setCity($overrides['city'] ?? 'Sydney')
        ->setState($overrides['state'] ?? 'New South Wales')
        ->setZip($overrides['zip'] ?? '2000')
        ->setCountry($overrides['country'] ?? 'AU')
        ->setCompany($overrides['company'] ?? 'Example Pty Ltd');

    $domain = (new Registrar_Domain())
        ->setSld($overrides['sld'] ?? 'example-test')
        ->setTld($overrides['tld'] ?? '.com.au')
        ->setRegistrationPeriod($overrides['years'] ?? 1)
        ->setNs1($overrides['ns1'] ?? 'ns1.example.com')
        ->setNs2($overrides['ns2'] ?? 'ns2.example.com')
        ->setPrivacyEnabled($overrides['privacy'] ?? false)
        ->setContactRegistrar($contact);

    if (isset($overrides['epp'])) {
        $domain->setEpp($overrides['epp']);
    }

    return $domain;
}

function buildSynergyAdapter(?SoapClient $client = null): Registrar_Adapter_Synergy
{
    $adapter = new Registrar_Adapter_Synergy([
        'reseller_id' => '12345',
        'api_key' => 'test-key',
    ]);

    if ($client instanceof SoapClient) {
        $adapter->setSoapClient($client);
    }

    return $adapter;
}

describe('Registrar_Adapter_Synergy', function (): void {
    test('getConfig exposes reseller credentials', function (): void {
        $config = Registrar_Adapter_Synergy::getConfig();

        expect($config['form'])->toHaveKeys(['reseller_id', 'api_key']);
    });

    test('isDomainAvailable treats AVAILABLE statuses as free', function (): void {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->with('checkDomain', Mockery::type('array'))
            ->andReturn((object) ['status' => 'AVAILABLE']);

        $adapter = buildSynergyAdapter($client);

        expect($adapter->isDomainAvailable(buildSynergyDomain()))->toBeTrue();
    });

    test('registerDomain sends unprefixed contacts with E.164 phone and AU state', function (): void {
        $captured = null;
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->with('domainRegister', Mockery::on(function (array $args) use (&$captured): bool {
                $captured = $args[0] ?? null;

                return is_array($captured);
            }))
            ->andReturn((object) ['status' => 'OK']);

        $adapter = buildSynergyAdapter($client);
        $result = $adapter->registerDomain(buildSynergyDomain());

        expect($result)->toBeTrue()
            ->and($captured['resellerID'])->toBe('12345')
            ->and($captured['apiKey'])->toBe('test-key')
            ->and($captured['domainName'])->toBe('example-test.com.au')
            ->and($captured['firstname'])->toBe('Jane')
            ->and($captured['suburb'])->toBe('Sydney')
            ->and($captured['state'])->toBe('NSW')
            ->and($captured['phone'])->toBe('+61412345678')
            ->and($captured['address'])->toBe(['1 Example St'])
            ->and($captured)->not->toHaveKey('registrant_firstname');
    });

    test('modifyContact prefixes contact roles', function (): void {
        $captured = null;
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->with('updateContact', Mockery::on(function (array $args) use (&$captured): bool {
                $captured = $args[0] ?? null;

                return is_array($captured);
            }))
            ->andReturn((object) ['status' => 'OK']);

        $adapter = buildSynergyAdapter($client);
        $adapter->modifyContact(buildSynergyDomain(['state' => 'VIC', 'tel' => '+61499887766']));

        expect($captured['registrant_firstname'])->toBe('Jane')
            ->and($captured['registrant_state'])->toBe('VIC')
            ->and($captured['registrant_phone'])->toBe('+61499887766')
            ->and($captured['admin_email'])->toBe('jane@example.com')
            ->and($captured['technical_suburb'])->toBe('Sydney')
            ->and($captured['billing_country'])->toBe('AU');
    });

    test('error statuses raise Registrar_Exception on mutating calls', function (): void {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->andReturn((object) [
                'status' => 'ERR_DOMAIN_NOT_AVAILABLE',
                'errorMessage' => 'Domain is not available',
            ]);

        $adapter = buildSynergyAdapter($client);

        expect(fn () => $adapter->renewDomain(buildSynergyDomain()))
            ->toThrow(Registrar_Exception::class);
    });

    test('isDomainAvailable returns false for UNAVAILABLE without throwing', function (): void {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->andReturn((object) [
                'status' => 'UNAVAILABLE',
                'errorMessage' => 'Domain is not available for registration.',
            ]);

        $adapter = buildSynergyAdapter($client);

        expect($adapter->isDomainAvailable(buildSynergyDomain()))->toBeFalse();
    });

    test('listDnsZone normalises Synergy record fields', function (): void {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->with('listDNSZone', Mockery::type('array'))
            ->andReturn((object) [
                'status' => 'OK',
                'records' => [
                    (object) [
                        'id' => 'abc123',
                        'hostName' => 'www.example-test.com.au',
                        'type' => 'A',
                        'content' => '1.2.3.4',
                        'ttl' => '3600',
                        'prio' => '0',
                    ],
                ],
            ]);

        $adapter = buildSynergyAdapter($client);
        $records = $adapter->listDnsZone('example-test.com.au');

        expect($records)->toHaveCount(1)
            ->and($records[0]['id'])->toBe('abc123')
            ->and($records[0]['hostname'])->toBe('www.example-test.com.au')
            ->and($records[0]['type'])->toBe('A')
            ->and($records[0]['content'])->toBe('1.2.3.4')
            ->and($records[0]['priority'])->toBeNull();
    });

    test('addDnsRecord sends Synergy request field names', function (): void {
        $captured = null;
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->with('addDNSRecord', Mockery::on(function (array $args) use (&$captured): bool {
                $captured = $args[0] ?? null;

                return is_array($captured);
            }))
            ->andReturn((object) ['status' => 'OK', 'id' => 'rec1']);

        $adapter = buildSynergyAdapter($client);
        $result = $adapter->addDnsRecord('example-test.com.au', 'www', 'A', '1.2.3.4', 3600);

        expect($result['id'])->toBe('rec1')
            ->and($captured['domainName'])->toBe('example-test.com.au')
            ->and($captured['recordName'])->toBe('www')
            ->and($captured['recordType'])->toBe('A')
            ->and($captured['recordContent'])->toBe('1.2.3.4')
            ->and($captured['recordTTL'])->toBe(3600)
            ->and($captured['recordPrio'])->toBe(0);
    });

    test('upsertDnsRecord updates when hostname and type already exist', function (): void {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__soapCall')
            ->once()
            ->with('listDNSZone', Mockery::type('array'))
            ->andReturn((object) [
                'status' => 'OK',
                'records' => [
                    (object) [
                        'id' => 'apex-a',
                        'hostName' => 'example-test.com.au',
                        'type' => 'A',
                        'content' => '9.9.9.9',
                        'ttl' => '3600',
                        'prio' => '0',
                    ],
                ],
            ]);
        $client->shouldReceive('__soapCall')
            ->once()
            ->with('updateDNSRecord', Mockery::on(function (array $args): bool {
                $req = $args[0] ?? [];

                return ($req['recordID'] ?? null) === 'apex-a'
                    && ($req['recordContent'] ?? null) === '1.2.3.4'
                    && ($req['recordTTL'] ?? null) === '3600';
            }))
            ->andReturn((object) ['status' => 'OK']);

        $adapter = buildSynergyAdapter($client);
        $result = $adapter->upsertDnsRecord('example-test.com.au', '@', 'A', '1.2.3.4');

        expect($result['action'])->toBe('updated')->and($result['id'])->toBe('apex-a');
    });

    test('applyHostingDns applies web and mail records', function (): void {
        $client = Mockery::mock(SoapClient::class);
        // ensureDnsZone list
        $client->shouldReceive('__soapCall')->with('listDNSZone', Mockery::type('array'))->andReturn((object) [
            'status' => 'OK',
            'records' => [],
        ]);
        // adds for apex A, www A, mail A, MX, SPF, DMARC, DKIM
        $client->shouldReceive('__soapCall')
            ->with('addDNSRecord', Mockery::type('array'))
            ->times(7)
            ->andReturn((object) ['status' => 'OK', 'id' => 'new']);

        $adapter = buildSynergyAdapter($client);
        $result = $adapter->applyHostingDns('example-test.com.au', '173.249.33.154', [
            'dkim_txt' => 'v=DKIM1; p=abc',
        ]);

        expect($result['web'])->toHaveKeys(['apex_a', 'www_a'])
            ->and($result['mail'])->toHaveKeys(['mail_a', 'mx', 'spf', 'dmarc', 'dkim'])
            ->and($result['mail']['dkim']['action'])->toBe('added');
    });
});
