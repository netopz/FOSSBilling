<?php

declare(strict_types=1);

namespace Box\Mod\SynergyDns;

use FOSSBilling\InformationException;
use SoapClient;
use SoapFault;

/**
 * Synergy Wholesale SOAP wrapper for DNS management operations.
 *
 * Credentials are read from the FOSSBilling registrar config for the
 * "Synergy" adapter stored in the `registrar` table, so there is one
 * source of truth and no duplicate config.
 *
 * SOAP field naming note (Synergy API):
 *   Request: recordName, recordType, recordContent, recordTTL, recordPrio, recordID
 *   Response (singleDNSZoneEntry): hostName, type, content, ttl, prio, id
 */
class Service implements \FOSSBilling\InjectionAwareInterface
{
    private ?\Pimple\Container $di = null;

    private const WSDL = 'https://api.synergywholesale.com/server.php?wsdl';

    private ?SoapClient $client = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    // ── Credentials ──────────────────────────────────────────────────────────

    /**
     * Load Synergy credentials from the FOSSBilling registrar config.
     *
     * @return array{resellerID: string, apiKey: string}
     *
     * @throws InformationException if credentials are not configured
     */
    private function credentials(): array
    {
        $registrar = $this->di['db']->findOne('Registrar', 'name = ?', ['Synergy']);
        if (!$registrar) {
            throw new InformationException('The Synergy Wholesale registrar is not configured in FOSSBilling.');
        }

        $config = $this->di['db']->findOne('RegistrarConfig', 'registrar_id = ? AND param = ?', [$registrar->id, 'reseller_id']);
        $keyRow  = $this->di['db']->findOne('RegistrarConfig', 'registrar_id = ? AND param = ?', [$registrar->id, 'api_key']);

        $resellerId = $config ? $config->value : null;
        $apiKey     = $keyRow  ? $keyRow->value  : null;

        if (empty($resellerId) || empty($apiKey)) {
            throw new InformationException('Synergy Wholesale credentials (Reseller ID / API Key) are not set.');
        }

        return ['resellerID' => $resellerId, 'apiKey' => $apiKey];
    }

    private function auth(): array
    {
        return $this->credentials();
    }

    // ── SOAP client ──────────────────────────────────────────────────────────

    private function client(): SoapClient
    {
        if ($this->client === null) {
            try {
                $this->client = new SoapClient(self::WSDL, [
                    'exceptions'   => true,
                    'trace'        => false,
                    'features'     => SOAP_SINGLE_ELEMENT_ARRAYS,
                    'stream_context' => stream_context_create([
                        'ssl' => ['verify_peer' => true],
                    ]),
                ]);
            } catch (SoapFault $e) {
                throw new InformationException('Unable to connect to Synergy Wholesale API: :msg', [':msg' => $e->getMessage()]);
            }
        }

        return $this->client;
    }

    /**
     * Execute a Synergy SOAP call.  Auth fields are merged into every request.
     *
     * @throws InformationException on API error or non-OK status
     */
    private function call(string $method, array $params = []): object
    {
        $request = array_merge($this->auth(), $params);
        try {
            $result = $this->client()->__soapCall($method, [['request' => $request]]);
        } catch (SoapFault $e) {
            throw new InformationException('Synergy API SOAP fault [:method]: :msg', [':method' => $method, ':msg' => $e->getMessage()]);
        }

        if ($result === null) {
            throw new InformationException('No response from Synergy Wholesale for :method.', [':method' => $method]);
        }

        $status = $result->status ?? null;
        if ($status !== null && !str_starts_with(strtoupper((string) $status), 'OK') && !str_starts_with(strtoupper((string) $status), 'AVAILABLE')) {
            $description = $result->errorMessage ?? $result->statusDescription ?? $status;
            throw new InformationException('Synergy Wholesale: :desc', [':desc' => $description]);
        }

        return $result;
    }

    // ── DNS Zone ─────────────────────────────────────────────────────────────

    /**
     * List all DNS records for a domain.
     *
     * @return array<int, array{recordID: string|null, hostname: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    public function listRecords(string $domainName): array
    {
        $result  = $this->call('listDNSZone', ['domainName' => $domainName]);
        $records = $result->records ?? null;

        if ($records === null) {
            return [];
        }

        // SOAP_SINGLE_ELEMENT_ARRAYS guarantees a PHP array here.
        $items = is_array($records) ? $records : (isset($records->item) ? (array) $records->item : []);

        $out = [];
        foreach ($items as $r) {
            $ttlRaw  = $r->ttl  ?? null;
            $prioRaw = $r->prio ?? null;
            $out[] = [
                'recordID' => $r->id    ?? null,
                'hostname' => (string) ($r->hostName ?? ''),
                'type'     => (string) ($r->type     ?? ''),
                'content'  => (string) ($r->content  ?? ''),
                'ttl'      => $ttlRaw  !== null ? (int) $ttlRaw  : 3600,
                'priority' => ($prioRaw !== null && (string) $prioRaw !== '0') ? (int) $prioRaw : null,
            ];
        }

        return $out;
    }

    /**
     * Create a DNS zone for a domain (required before adding records).
     */
    public function createZone(string $domainName): bool
    {
        $this->call('addDNSZone', ['domainName' => $domainName]);

        return true;
    }

    /**
     * Add a DNS record.
     *
     * @return string|null The new record ID returned by Synergy (hex string)
     */
    public function addRecord(string $domainName, string $hostname, string $type, string $content, int $ttl = 3600, ?int $priority = null): ?string
    {
        $result = $this->call('addDNSRecord', [
            'domainName'    => $domainName,
            'recordName'    => $hostname,
            'recordType'    => $type,
            'recordContent' => $content,
            'recordTTL'     => $ttl,
            'recordPrio'    => $priority ?? 0,
        ]);

        return $result->id ?? $result->recordID ?? null;
    }

    /**
     * Update an existing DNS record by its ID (hex string).
     */
    public function updateRecord(string $domainName, string $recordId, string $hostname, string $type, string $content, int $ttl = 3600, ?int $priority = null): bool
    {
        $this->call('updateDNSRecord', [
            'domainName'    => $domainName,
            'recordID'      => $recordId,
            'recordName'    => $hostname,
            'recordType'    => $type,
            'recordContent' => $content,
            'recordTTL'     => (string) $ttl,
            'recordPrio'    => $priority ?? 0,
        ]);

        return true;
    }

    /**
     * Delete a DNS record by its ID.
     */
    public function deleteRecord(string $recordId): bool
    {
        $this->call('deleteDNSRecord', ['id' => $recordId]);

        return true;
    }

    // ── DNSSEC ───────────────────────────────────────────────────────────────

    /**
     * List DNSSEC DS records for a domain.
     *
     * @return array<int, array{uuid: string|null, algorithm: int|null, digestType: int|null, digest: string|null, keyTag: int|null}>
     */
    public function listDnssec(string $domainName): array
    {
        $result = $this->call('DNSSECListDS', ['domainName' => $domainName]);
        $dsData = $result->DSData ?? null;

        if ($dsData === null) {
            return [];
        }

        $items = is_array($dsData) ? $dsData : [$dsData];

        $out = [];
        foreach ($items as $record) {
            $out[] = [
                'uuid'       => $record->UUID       ?? $record->uuid       ?? null,
                'algorithm'  => isset($record->algorithm)  ? (int) $record->algorithm  : null,
                'digestType' => isset($record->digestType) ? (int) $record->digestType : null,
                'digest'     => $record->digest     ?? null,
                'keyTag'     => isset($record->keyTag)     ? (int) $record->keyTag     : null,
            ];
        }

        return $out;
    }

    /**
     * Add a DNSSEC DS record.
     */
    public function addDnssec(string $domainName, int $algorithm, int $digestType, string $digest, int $keyTag): bool
    {
        $this->call('DNSSECAddDS', [
            'domainName' => $domainName,
            'algorithm'  => $algorithm,
            'digestType' => $digestType,
            'digest'     => $digest,
            'keyTag'     => $keyTag,
        ]);

        return true;
    }

    /**
     * Remove a DNSSEC DS record by UUID.
     */
    public function deleteDnssec(string $domainName, string $uuid): bool
    {
        $this->call('DNSSECRemoveDS', ['domainName' => $domainName, 'UUID' => $uuid]);

        return true;
    }

    // ── Child Hosts (Glue Records) ────────────────────────────────────────────

    /**
     * List child host (glue) records for a domain.
     *
     * @return array<int, array{hostname: string|null, ipAddresses: list<string>}>
     */
    public function listChildHosts(string $domainName): array
    {
        $result   = $this->call('listAllHosts', ['domainName' => $domainName]);
        $hostsRaw = $result->hosts ?? null;

        if ($hostsRaw === null) {
            return [];
        }

        $hosts = is_array($hostsRaw) ? $hostsRaw : [$hostsRaw];

        $out = [];
        foreach ($hosts as $host) {
            $hostname = $host->hostName ?? $host->hostname ?? null;
            $ipsRaw   = $host->ip ?? [];
            $ips      = is_array($ipsRaw) ? $ipsRaw : [$ipsRaw];
            $out[] = [
                'hostname'    => $hostname !== null ? (string) $hostname : null,
                'ipAddresses' => array_values(array_filter(array_map('strval', $ips))),
            ];
        }

        return $out;
    }

    /**
     * Add a child host (glue record) with one or more IP addresses.
     *
     * @param list<string> $ipAddresses
     */
    public function addChildHost(string $domainName, string $host, array $ipAddresses): bool
    {
        $this->call('addHost', [
            'domainName' => $domainName,
            'host'       => $host,
            'ipAddress'  => $ipAddresses,
        ]);

        return true;
    }

    /**
     * Delete a child host record.
     */
    public function deleteChildHost(string $domainName, string $host): bool
    {
        $this->call('deleteHost', ['domainName' => $domainName, 'host' => $host]);

        return true;
    }

    // ── Mail Forwards ─────────────────────────────────────────────────────────

    /**
     * List email forwarders for a domain.
     *
     * @return array<int, array{recordID: string|null, source: string, destination: string, prefix: string}>
     */
    public function listMailForwards(string $domainName): array
    {
        $result      = $this->call('listMailForwards', ['domainName' => $domainName]);
        $forwardsRaw = $result->forwards ?? null;

        if ($forwardsRaw === null) {
            return [];
        }

        $items = $forwardsRaw->item ?? $forwardsRaw;
        if (!is_array($items)) {
            $items = [$items];
        }

        $out = [];
        foreach ($items as $fwd) {
            $source = (string) ($fwd->source ?? '');
            $prefix = str_contains($source, '@') ? explode('@', $source)[0] : $source;
            $out[] = [
                'recordID'    => $fwd->id ?? null,
                'source'      => $source,
                'destination' => (string) ($fwd->destination ?? ''),
                'prefix'      => $prefix,
            ];
        }

        return $out;
    }

    /**
     * Add an email forwarder (prefix@domain → destination).
     *
     * @return string|null The new record ID
     */
    public function addMailForward(string $domainName, string $prefix, string $destination): ?string
    {
        $source = str_contains($prefix, '@') ? $prefix : "{$prefix}@{$domainName}";
        $result = $this->call('addMailForward', [
            'domainName'  => $domainName,
            'source'      => $source,
            'destination' => $destination,
        ]);

        return $result->id ?? null;
    }

    /**
     * Delete an email forwarder by ID.
     */
    public function deleteMailForward(string $domainName, string $forwardId): bool
    {
        $this->call('deleteMailForward', ['domainName' => $domainName, 'forwardID' => $forwardId]);

        return true;
    }
}
