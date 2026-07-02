<?php

declare(strict_types=1);

namespace Box\Mod\SynergyDns\Api;

/**
 * Admin-level Synergy DNS API endpoints.
 *
 * These mirror all client endpoints but are accessible to admins so they can
 * manage DNS on behalf of any client domain.
 *
 * All endpoints accept `domain_name` (string, required) plus operation-specific
 * parameters.  See Client.php for parameter documentation — this class delegates
 * to the same Service methods.
 */
class Admin extends \Api_Abstract
{
    // ── DNS Records ──────────────────────────────────────────────────────────

    /**
     * List DNS records for a domain.
     *
     * @param array{domain_name: string} $data
     *
     * @return list<array{recordID: string|null, hostname: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    public function list_records(array $data): array
    {
        $domainName = $this->_requireParam($data, 'domain_name');

        return $this->getService()->listRecords($domainName);
    }

    /**
     * Create a DNS zone for a domain.
     *
     * @param array{domain_name: string} $data
     */
    public function create_zone(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');

        return $this->getService()->createZone($domainName);
    }

    /**
     * Add a DNS record.
     *
     * @param array{domain_name: string, hostname: string, type: string, content: string, ttl?: int, priority?: int} $data
     *
     * @return array{recordID: string|null, status: string}
     */
    public function add_record(array $data): array
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $hostname   = $this->_requireParam($data, 'hostname');
        $type       = strtoupper($this->_requireParam($data, 'type'));
        $content    = $this->_requireParam($data, 'content');
        $ttl        = isset($data['ttl']) ? (int) $data['ttl'] : 3600;
        $priority   = isset($data['priority']) ? (int) $data['priority'] : null;

        $recordId = $this->getService()->addRecord($domainName, $hostname, $type, $content, $ttl, $priority);

        return ['recordID' => $recordId, 'status' => 'OK'];
    }

    /**
     * Update an existing DNS record.
     *
     * @param array{domain_name: string, record_id: string, hostname: string, type: string, content: string, ttl?: int, priority?: int} $data
     */
    public function update_record(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $recordId   = $this->_requireParam($data, 'record_id');
        $hostname   = $this->_requireParam($data, 'hostname');
        $type       = strtoupper($this->_requireParam($data, 'type'));
        $content    = $this->_requireParam($data, 'content');
        $ttl        = isset($data['ttl']) ? (int) $data['ttl'] : 3600;
        $priority   = isset($data['priority']) ? (int) $data['priority'] : null;

        return $this->getService()->updateRecord($domainName, $recordId, $hostname, $type, $content, $ttl, $priority);
    }

    /**
     * Delete a DNS record by ID.
     *
     * @param array{record_id: string} $data
     */
    public function delete_record(array $data): bool
    {
        $recordId = $this->_requireParam($data, 'record_id');

        return $this->getService()->deleteRecord($recordId);
    }

    // ── DNSSEC ───────────────────────────────────────────────────────────────

    /**
     * List DNSSEC DS records for a domain.
     *
     * @param array{domain_name: string} $data
     *
     * @return list<array{uuid: string|null, algorithm: int|null, digestType: int|null, digest: string|null, keyTag: int|null}>
     */
    public function list_dnssec(array $data): array
    {
        $domainName = $this->_requireParam($data, 'domain_name');

        return $this->getService()->listDnssec($domainName);
    }

    /**
     * Add a DNSSEC DS record.
     *
     * @param array{domain_name: string, algorithm: int, digest_type: int, digest: string, key_tag: int} $data
     */
    public function add_dnssec(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $algorithm  = (int) $this->_requireParam($data, 'algorithm');
        $digestType = (int) $this->_requireParam($data, 'digest_type');
        $digest     = $this->_requireParam($data, 'digest');
        $keyTag     = (int) $this->_requireParam($data, 'key_tag');

        return $this->getService()->addDnssec($domainName, $algorithm, $digestType, $digest, $keyTag);
    }

    /**
     * Remove a DNSSEC DS record.
     *
     * @param array{domain_name: string, uuid: string} $data
     */
    public function delete_dnssec(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $uuid       = $this->_requireParam($data, 'uuid');

        return $this->getService()->deleteDnssec($domainName, $uuid);
    }

    // ── Child Hosts ───────────────────────────────────────────────────────────

    /**
     * List child host (glue) records for a domain.
     *
     * @param array{domain_name: string} $data
     *
     * @return list<array{hostname: string|null, ipAddresses: list<string>}>
     */
    public function list_child_hosts(array $data): array
    {
        $domainName = $this->_requireParam($data, 'domain_name');

        return $this->getService()->listChildHosts($domainName);
    }

    /**
     * Add a child host (glue record).
     *
     * @param array{domain_name: string, host: string, ip_addresses: list<string>} $data
     */
    public function add_child_host(array $data): bool
    {
        $domainName  = $this->_requireParam($data, 'domain_name');
        $host        = $this->_requireParam($data, 'host');
        $ipAddresses = $this->_requireParam($data, 'ip_addresses');

        if (!is_array($ipAddresses) || count($ipAddresses) === 0) {
            throw new \FOSSBilling\InformationException('At least one IP address is required.');
        }

        return $this->getService()->addChildHost($domainName, $host, array_values($ipAddresses));
    }

    /**
     * Delete a child host record.
     *
     * @param array{domain_name: string, host: string} $data
     */
    public function delete_child_host(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $host       = $this->_requireParam($data, 'host');

        return $this->getService()->deleteChildHost($domainName, $host);
    }

    // ── Mail Forwards ─────────────────────────────────────────────────────────

    /**
     * List email forwarders for a domain.
     *
     * @param array{domain_name: string} $data
     *
     * @return list<array{recordID: string|null, source: string, destination: string, prefix: string}>
     */
    public function list_mail_forwards(array $data): array
    {
        $domainName = $this->_requireParam($data, 'domain_name');

        return $this->getService()->listMailForwards($domainName);
    }

    /**
     * Add an email forwarder.
     *
     * @param array{domain_name: string, prefix: string, destination: string} $data
     *
     * @return array{recordID: string|null, status: string}
     */
    public function add_mail_forward(array $data): array
    {
        $domainName  = $this->_requireParam($data, 'domain_name');
        $prefix      = $this->_requireParam($data, 'prefix');
        $destination = $this->_requireParam($data, 'destination');

        $recordId = $this->getService()->addMailForward($domainName, $prefix, $destination);

        return ['recordID' => $recordId, 'status' => 'OK'];
    }

    /**
     * Delete an email forwarder.
     *
     * @param array{domain_name: string, forward_id: string} $data
     */
    public function delete_mail_forward(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $forwardId  = $this->_requireParam($data, 'forward_id');

        return $this->getService()->deleteMailForward($domainName, $forwardId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @throws \FOSSBilling\InformationException if the param is missing or empty
     */
    private function _requireParam(array $data, string $key): mixed
    {
        if (!isset($data[$key]) || (is_string($data[$key]) && trim($data[$key]) === '')) {
            throw new \FOSSBilling\InformationException('Parameter ":param" is required.', [':param' => $key]);
        }

        return $data[$key];
    }
}
