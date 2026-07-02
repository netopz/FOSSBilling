<?php

declare(strict_types=1);

namespace Box\Mod\SynergyDns\Api;

use FOSSBilling\InformationException;

/**
 * Client-level Synergy DNS API endpoints.
 *
 * Every endpoint validates that the requested domain belongs to the currently
 * authenticated client before calling Synergy, preventing cross-client access.
 *
 * FOSSBilling endpoint names → method names:
 *   client.synergydns.list_records      → list_records()
 *   client.synergydns.create_zone       → create_zone()
 *   client.synergydns.add_record        → add_record()
 *   client.synergydns.update_record     → update_record()
 *   client.synergydns.delete_record     → delete_record()
 *   client.synergydns.list_dnssec       → list_dnssec()
 *   client.synergydns.add_dnssec        → add_dnssec()
 *   client.synergydns.delete_dnssec     → delete_dnssec()
 *   client.synergydns.list_child_hosts  → list_child_hosts()
 *   client.synergydns.add_child_host    → add_child_host()
 *   client.synergydns.delete_child_host → delete_child_host()
 *   client.synergydns.list_mail_forwards → list_mail_forwards()
 *   client.synergydns.add_mail_forward  → add_mail_forward()
 *   client.synergydns.delete_mail_forward → delete_mail_forward()
 */
class Client extends \Api_Abstract
{
    // ── DNS Records ──────────────────────────────────────────────────────────

    /**
     * List DNS records for one of the authenticated client's domains.
     *
     * @param array{domain_name: string} $data
     *
     * @return list<array{recordID: string|null, hostname: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    public function list_records(array $data): array
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        return $this->getService()->listRecords($domainName);
    }

    /**
     * Create a DNS zone for the domain (call once before adding records).
     *
     * @param array{domain_name: string} $data
     */
    public function create_zone(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        return $this->getService()->createZone($domainName);
    }

    /**
     * Add a DNS record.
     *
     * @param array{domain_name: string, hostname: string, type: string, content: string, ttl?: int, priority?: int} $data
     *   - hostname: record name relative to zone root (e.g. `@`, `www`, `mail`)
     *   - type: A | AAAA | CNAME | MX | TXT | SRV | CAA
     *   - content: record value
     *   - ttl: time-to-live in seconds (default 3600)
     *   - priority: MX/SRV priority (omit for other types)
     *
     * @return array{recordID: string|null, status: string}
     */
    public function add_record(array $data): array
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        $hostname = $this->_requireParam($data, 'hostname');
        $type     = strtoupper($this->_requireParam($data, 'type'));
        $content  = $this->_requireParam($data, 'content');
        $ttl      = isset($data['ttl']) ? (int) $data['ttl'] : 3600;
        $priority = isset($data['priority']) ? (int) $data['priority'] : null;

        $recordId = $this->getService()->addRecord($domainName, $hostname, $type, $content, $ttl, $priority);

        return ['recordID' => $recordId, 'status' => 'OK'];
    }

    /**
     * Update an existing DNS record by its ID.
     *
     * @param array{domain_name: string, record_id: string, hostname: string, type: string, content: string, ttl?: int, priority?: int} $data
     */
    public function update_record(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        $recordId = $this->_requireParam($data, 'record_id');
        $hostname = $this->_requireParam($data, 'hostname');
        $type     = strtoupper($this->_requireParam($data, 'type'));
        $content  = $this->_requireParam($data, 'content');
        $ttl      = isset($data['ttl']) ? (int) $data['ttl'] : 3600;
        $priority = isset($data['priority']) ? (int) $data['priority'] : null;

        return $this->getService()->updateRecord($domainName, $recordId, $hostname, $type, $content, $ttl, $priority);
    }

    /**
     * Delete a DNS record by its Synergy record ID (hex string).
     *
     * Note: Synergy's deleteDNSRecord only accepts the record ID, not the
     * domain name, so ownership is verified via domain_name before the call.
     *
     * @param array{domain_name: string, record_id: string} $data
     */
    public function delete_record(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

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
        $this->_assertOwns($domainName);

        return $this->getService()->listDnssec($domainName);
    }

    /**
     * Add a DNSSEC DS record.
     *
     * @param array{domain_name: string, algorithm: int, digest_type: int, digest: string, key_tag: int} $data
     *   - algorithm: DNSSEC algorithm number (e.g. 13 = ECDSAP256SHA256)
     *   - digest_type: digest type number (e.g. 2 = SHA-256)
     *   - digest: hex digest string
     *   - key_tag: key tag integer
     */
    public function add_dnssec(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        $algorithm  = (int) $this->_requireParam($data, 'algorithm');
        $digestType = (int) $this->_requireParam($data, 'digest_type');
        $digest     = $this->_requireParam($data, 'digest');
        $keyTag     = (int) $this->_requireParam($data, 'key_tag');

        return $this->getService()->addDnssec($domainName, $algorithm, $digestType, $digest, $keyTag);
    }

    /**
     * Remove a DNSSEC DS record by UUID.
     *
     * @param array{domain_name: string, uuid: string} $data
     */
    public function delete_dnssec(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        $uuid = $this->_requireParam($data, 'uuid');

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
        $this->_assertOwns($domainName);

        return $this->getService()->listChildHosts($domainName);
    }

    /**
     * Add a child host (glue record).
     *
     * @param array{domain_name: string, host: string, ip_addresses: list<string>} $data
     *   - host: fully-qualified hostname (e.g. `ns1.example.com`)
     *   - ip_addresses: one or more IPv4/IPv6 addresses
     */
    public function add_child_host(array $data): bool
    {
        $domainName  = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        $host        = $this->_requireParam($data, 'host');
        $ipAddresses = $this->_requireParam($data, 'ip_addresses');

        if (!is_array($ipAddresses) || count($ipAddresses) === 0) {
            throw new InformationException('At least one IP address is required.');
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
        $this->_assertOwns($domainName);

        $host = $this->_requireParam($data, 'host');

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
        $this->_assertOwns($domainName);

        return $this->getService()->listMailForwards($domainName);
    }

    /**
     * Add an email forwarder (prefix@domain → destination).
     *
     * @param array{domain_name: string, prefix: string, destination: string} $data
     *   - prefix: local part of the source address (e.g. `info`, `sales`)
     *   - destination: full target email address
     *
     * @return array{recordID: string|null, status: string}
     */
    public function add_mail_forward(array $data): array
    {
        $domainName  = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        $prefix      = $this->_requireParam($data, 'prefix');
        $destination = $this->_requireParam($data, 'destination');

        $recordId = $this->getService()->addMailForward($domainName, $prefix, $destination);

        return ['recordID' => $recordId, 'status' => 'OK'];
    }

    /**
     * Delete an email forwarder by ID.
     *
     * @param array{domain_name: string, forward_id: string} $data
     */
    public function delete_mail_forward(array $data): bool
    {
        $domainName = $this->_requireParam($data, 'domain_name');
        $this->_assertOwns($domainName);

        $forwardId = $this->_requireParam($data, 'forward_id');

        return $this->getService()->deleteMailForward($domainName, $forwardId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Verify that the authenticated client owns the given domain.
     *
     * Looks up the domain in the `service_domain` table joined through the
     * client's orders.  Throws a permission error if not found.
     *
     * @throws InformationException if the domain does not belong to this client
     */
    private function _assertOwns(string $domainName): void
    {
        $client = $this->getIdentity();

        $domain = $this->di['db']->findOne(
            'ServiceDomain',
            'sld = ? AND tld = ? AND client_id = ?',
            [...$this->_splitDomain($domainName), $client->id],
        );

        if (!$domain) {
            throw new InformationException('Domain :domain not found in your account.', [':domain' => $domainName]);
        }
    }

    /**
     * Split a FQDN into [sld, tld] pair.
     * e.g. "example.com.au" → ["example", ".com.au"]
     *
     * @return array{0: string, 1: string}
     */
    private function _splitDomain(string $domainName): array
    {
        $parts = explode('.', ltrim($domainName, '.'), 2);

        return [$parts[0], '.' . ($parts[1] ?? '')];
    }

    /**
     * @throws InformationException if the param is missing or empty
     */
    private function _requireParam(array $data, string $key): mixed
    {
        if (!isset($data[$key]) || (is_string($data[$key]) && trim($data[$key]) === '')) {
            throw new InformationException('Parameter ":param" is required.', [':param' => $key]);
        }

        return $data[$key];
    }
}
