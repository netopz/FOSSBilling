<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling / Vioflare
 * SPDX-License-Identifier: Apache-2.0.
 *
 * Cloudflare DNS provider (not a registrar). Synergy remains the registrar;
 * this adapter owns zones, records, and orange-cloud proxy flags.
 *
 * @see https://developers.cloudflare.com/api/
 */

class Dns_Adapter_Cloudflare
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /** @var array{api_token: string, account_id: string, enabled: bool} */
    private array $config;

    /** @var callable|null fn(string $method, string $path, ?array $body): array */
    private $httpHandler = null;

    private mixed $log = null;

    /**
     * @param array{api_token?: string, account_id?: string, enabled?: bool|string|int} $options
     */
    public function __construct(array $options)
    {
        $token = trim((string) ($options['api_token'] ?? ''));
        $accountId = trim((string) ($options['account_id'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Cloudflare DNS is not configured: missing api_token.');
        }
        if ($accountId === '') {
            throw new RuntimeException('Cloudflare DNS is not configured: missing account_id.');
        }

        $this->config = [
            'api_token' => $token,
            'account_id' => $accountId,
            'enabled' => $this->truthy($options['enabled'] ?? true),
        ];
    }

    /**
     * Build from FOSSBilling config.php key `cloudflare_dns`, or null if disabled/missing.
     */
    public static function fromFossConfig(): ?self
    {
        if (!class_exists(\FOSSBilling\Config::class)) {
            return null;
        }
        $cfg = \FOSSBilling\Config::getProperty('cloudflare_dns', []);
        if (!is_array($cfg)) {
            return null;
        }
        if (!self::truthyStatic($cfg['enabled'] ?? false)) {
            return null;
        }
        $token = trim((string) ($cfg['api_token'] ?? ''));
        $accountId = trim((string) ($cfg['account_id'] ?? ''));
        if ($token === '' || $accountId === '') {
            return null;
        }

        try {
            return new self($cfg);
        } catch (Throwable) {
            return null;
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Cloudflare DNS / CDN / WAF (zones and records; Synergy stays registrar).',
            'form' => [
                'enabled' => [
                    'radio',
                    [
                        'label' => 'Enable Cloudflare DNS for new Synergy domains / hosting activate',
                        'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    ],
                ],
                'api_token' => [
                    'password',
                    [
                        'required' => true,
                        'label' => 'API token (Zone:Edit + DNS:Edit for the account)',
                    ],
                ],
                'account_id' => [
                    'text',
                    [
                        'required' => true,
                        'label' => 'Cloudflare Account ID',
                    ],
                ],
            ],
        ];
    }

    public function setLog(mixed $log): void
    {
        $this->log = $log;
    }

    /**
     * @param callable(string, string, ?array): array $handler
     */
    public function setHttpHandler(callable $handler): void
    {
        $this->httpHandler = $handler;
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    /**
     * Create zone if missing; return zone id + assigned nameservers.
     *
     * @return array{id: string, name: string, status: string, name_servers: list<string>}
     */
    public function ensureZone(string $domainName): array
    {
        $domainName = $this->normaliseDomain($domainName);
        $existing = $this->findZone($domainName);
        if ($existing !== null) {
            return $existing;
        }

        $result = $this->request('POST', '/zones', [
            'name' => $domainName,
            'account' => ['id' => $this->config['account_id']],
            'jump_start' => false,
            'type' => 'full',
        ]);

        return $this->mapZone(isset($result['result']) && is_array($result['result']) ? $result['result'] : $result);
    }

    /**
     * @return array{id: string, name: string, status: string, name_servers: list<string>}|null
     */
    public function findZone(string $domainName): ?array
    {
        $domainName = $this->normaliseDomain($domainName);
        $payload = $this->request('GET', '/zones?' . http_build_query([
            'name' => $domainName,
            'account.id' => $this->config['account_id'],
        ]));
        $list = $payload['result'] ?? $payload;
        if (!is_array($list) || $list === []) {
            return null;
        }
        // List endpoint wraps in result[]; create returns result object.
        if (isset($list[0]) && is_array($list[0])) {
            return $this->mapZone($list[0]);
        }
        if (isset($list['id'])) {
            return $this->mapZone($list);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getNameservers(string $domainName): array
    {
        $zone = $this->ensureZone($domainName);

        return $zone['name_servers'];
    }

    /**
     * List DNS records for a zone.
     *
     * @return list<array<string, mixed>>
     */
    public function listDnsRecords(string $domainName): array
    {
        $zone = $this->ensureZone($domainName);
        $out = [];
        $page = 1;
        do {
            $payload = $this->request(
                'GET',
                '/zones/' . rawurlencode($zone['id']) . '/dns_records?' . http_build_query([
                    'page' => $page,
                    'per_page' => 100,
                ])
            );
            $batch = $payload['result'] ?? [];
            if (!is_array($batch)) {
                break;
            }
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            $totalPages = (int) ($payload['result_info']['total_pages'] ?? 1);
            ++$page;
        } while ($page <= $totalPages);

        return $out;
    }

    /**
     * Upsert a DNS record. For A/AAAA/CNAME, $proxied controls orange-cloud.
     *
     * @return array{action: string, id: string|null}
     */
    public function upsertRecord(
        string $domainName,
        string $type,
        string $name,
        string $content,
        bool $proxied = false,
        int $ttl = 1,
        ?int $priority = null,
        array $match = [],
    ): array {
        $zone = $this->ensureZone($domainName);
        $type = strtoupper($type);
        $fqdn = $this->toFqdn($domainName, $name);
        $records = $this->listDnsRecords($domainName);
        $existing = $this->findMatchingRecord($records, $type, $fqdn, $match);

        $body = [
            'type' => $type,
            'name' => $fqdn,
            'ttl' => $proxied ? 1 : max(1, $ttl),
            'proxied' => $proxied && in_array($type, ['A', 'AAAA', 'CNAME'], true),
        ];

        if ($type === 'MX') {
            $body['content'] = rtrim($content, '.');
            $body['priority'] = $priority ?? 10;
        } elseif ($type === 'SRV') {
            // content: "weight port target" ; priority via $priority
            $parts = preg_split('/\s+/', trim($content)) ?: [];
            if (count($parts) < 3) {
                throw new RuntimeException('SRV content must be "weight port target".');
            }
            $body['data'] = [
                'priority' => $priority ?? 0,
                'weight' => (int) $parts[0],
                'port' => (int) $parts[1],
                'target' => rtrim($parts[2], '.'),
            ];
            unset($body['content']);
        } elseif ($type === 'TXT') {
            $body['content'] = $content;
        } else {
            $body['content'] = $content;
        }

        if ($existing !== null) {
            $same = $this->recordEquals($existing, $body, $type);
            if ($same) {
                return ['action' => 'unchanged', 'id' => (string) $existing['id']];
            }
            $updated = $this->request(
                'PATCH',
                '/zones/' . rawurlencode($zone['id']) . '/dns_records/' . rawurlencode((string) $existing['id']),
                $body
            );
            $id = (string) (($updated['result']['id'] ?? $updated['id'] ?? $existing['id']));

            return ['action' => 'updated', 'id' => $id];
        }

        $created = $this->request(
            'POST',
            '/zones/' . rawurlencode($zone['id']) . '/dns_records',
            $body
        );
        $id = (string) (($created['result']['id'] ?? $created['id'] ?? ''));

        return ['action' => 'added', 'id' => $id !== '' ? $id : null];
    }

    /**
     * Proxied @ and www A/AAAA → Pluto origin.
     *
     * @return array<string, array{action: string, id: string|null}>
     */
    public function applyWebDns(string $domainName, string $ipv4, ?string $ipv6 = null): array
    {
        $out = [
            'apex_a' => $this->upsertRecord($domainName, 'A', '@', $ipv4, true),
            'www_a' => $this->upsertRecord($domainName, 'A', 'www', $ipv4, true),
        ];
        if ($ipv6 !== null && $ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $out['apex_aaaa'] = $this->upsertRecord($domainName, 'AAAA', '@', $ipv6, true);
            $out['www_aaaa'] = $this->upsertRecord($domainName, 'AAAA', 'www', $ipv6, true);
        }

        return $out;
    }

    /**
     * Mail + discovery records — always DNS-only (grey cloud).
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
        bool $skipMx = false,
    ): array {
        $mxHost = $mxHost ?: ('mail.' . $domainName);
        $mxHost = rtrim($mxHost, '.');
        $mailTarget = 'mail.' . $this->normaliseDomain($domainName);
        $autodiscoverTarget = 'autodiscover.' . $this->normaliseDomain($domainName);

        $hasIpv6 = $ipv6 !== null && $ipv6 !== '' && (bool) filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $spf = $hasIpv6
            ? sprintf('v=spf1 a mx ip4:%s ip6:%s ~all', $ipv4, $ipv6)
            : sprintf('v=spf1 a mx ip4:%s ~all', $ipv4);
        $dmarc = $dmarc ?: 'v=DMARC1; p=none; rua=mailto:hostmaster@vioflare.com';

        $out = [
            'mail_a' => $this->upsertRecord($domainName, 'A', 'mail', $ipv4, false),
            'webmail_a' => $this->upsertRecord($domainName, 'A', 'webmail', $ipv4, false),
            'mx' => null,
            'spf' => null,
            'dmarc' => null,
            'dkim' => null,
            'autoconfig_a' => $this->upsertRecord($domainName, 'A', 'autoconfig', $ipv4, false),
            'autodiscover_a' => $this->upsertRecord($domainName, 'A', 'autodiscover', $ipv4, false),
            'srv_imaps' => $this->upsertRecord($domainName, 'SRV', '_imaps._tcp', '1 993 ' . $mailTarget, false, 1, 0),
            'srv_submission' => $this->upsertRecord($domainName, 'SRV', '_submission._tcp', '1 587 ' . $mailTarget, false, 1, 0),
            'srv_pop3s' => $this->upsertRecord($domainName, 'SRV', '_pop3s._tcp', '1 995 ' . $mailTarget, false, 1, 0),
            'srv_autodiscover' => $this->upsertRecord($domainName, 'SRV', '_autodiscover._tcp', '1 443 ' . $autodiscoverTarget, false, 1, 0),
        ];

        if (!$skipMx) {
            $out['mx'] = $this->upsertRecord($domainName, 'MX', '@', $mxHost, false, 1, 10, ['match_any_of_type' => true]);
            $out['spf'] = $this->upsertRecord($domainName, 'TXT', '@', $spf, false, 1, null, ['content_prefix' => 'v=spf1']);
            $out['dmarc'] = $this->upsertRecord($domainName, 'TXT', '_dmarc', $dmarc, false, 1, null, ['content_prefix' => 'v=DMARC1']);
        }

        if ($hasIpv6) {
            $out['mail_aaaa'] = $this->upsertRecord($domainName, 'AAAA', 'mail', $ipv6, false);
            $out['webmail_aaaa'] = $this->upsertRecord($domainName, 'AAAA', 'webmail', $ipv6, false);
            $out['autoconfig_aaaa'] = $this->upsertRecord($domainName, 'AAAA', 'autoconfig', $ipv6, false);
            $out['autodiscover_aaaa'] = $this->upsertRecord($domainName, 'AAAA', 'autodiscover', $ipv6, false);
        }

        if ($dkimTxt !== null && $dkimTxt !== '') {
            $out['dkim'] = $this->upsertRecord(
                $domainName,
                'TXT',
                'mail._domainkey',
                $dkimTxt,
                false,
                1,
                null,
                ['content_prefix' => 'v=DKIM1'],
            );
        }

        return $out;
    }

    /**
     * @param array{
     *   dkim_txt?: string,
     *   mx_host?: string,
     *   dmarc?: string,
     *   ipv6?: string,
     *   skip_mail?: bool,
     *   skip_mx?: bool
     * } $options
     *
     * @return array{web: array, mail: array|null, zone: array}
     */
    public function applyHostingDns(string $domainName, string $ipv4, array $options = []): array
    {
        $zone = $this->ensureZone($domainName);
        $ipv6 = isset($options['ipv6']) && is_string($options['ipv6']) ? $options['ipv6'] : null;
        $web = $this->applyWebDns($domainName, $ipv4, $ipv6);

        $mail = null;
        if (empty($options['skip_mail'])) {
            $mail = $this->applyMailDns(
                $domainName,
                $ipv4,
                $options['dkim_txt'] ?? null,
                $options['mx_host'] ?? null,
                $options['dmarc'] ?? null,
                $ipv6,
                !empty($options['skip_mx']),
            );
        }

        return [
            'zone' => $zone,
            'web' => $web,
            'mail' => $mail,
        ];
    }

    /**
     * Patch zone SSL mode to full (strict recommended at dashboard/API).
     */
    public function setSslMode(string $domainName, string $mode = 'full'): void
    {
        $zone = $this->ensureZone($domainName);
        $this->request('PATCH', '/zones/' . rawurlencode($zone['id']) . '/settings/ssl', [
            'value' => $mode,
        ]);
    }

    private function normaliseDomain(string $domainName): string
    {
        return strtolower(rtrim(trim($domainName), '.'));
    }

    private function toFqdn(string $domainName, string $name): string
    {
        $domainName = $this->normaliseDomain($domainName);
        $name = trim($name);
        if ($name === '' || $name === '@') {
            return $domainName;
        }
        $name = rtrim($name, '.');
        if (str_ends_with(strtolower($name), '.' . $domainName) || strtolower($name) === $domainName) {
            return strtolower($name);
        }

        return strtolower($name . '.' . $domainName);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param array{match_any_of_type?: bool, content_prefix?: string} $match
     *
     * @return array<string, mixed>|null
     */
    private function findMatchingRecord(array $records, string $type, string $fqdn, array $match): ?array
    {
        $prefix = isset($match['content_prefix']) ? (string) $match['content_prefix'] : null;
        $anyOfType = !empty($match['match_any_of_type']);
        foreach ($records as $row) {
            if (strtoupper((string) ($row['type'] ?? '')) !== $type) {
                continue;
            }
            $name = strtolower(rtrim((string) ($row['name'] ?? ''), '.'));
            if ($name !== strtolower(rtrim($fqdn, '.'))) {
                continue;
            }
            if ($prefix !== null) {
                $content = trim((string) ($row['content'] ?? ''), '"');
                if (!str_starts_with($content, $prefix)) {
                    continue;
                }
            }
            if ($anyOfType || $prefix !== null || true) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $body
     */
    private function recordEquals(array $existing, array $body, string $type): bool
    {
        $proxiedWant = (bool) ($body['proxied'] ?? false);
        $proxiedHave = (bool) ($existing['proxied'] ?? false);
        if ($proxiedWant !== $proxiedHave && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return false;
        }
        if ($type === 'SRV') {
            $data = $body['data'] ?? [];
            $have = $existing['data'] ?? [];
            if (!is_array($data) || !is_array($have)) {
                return false;
            }

            return (int) ($have['priority'] ?? -1) === (int) ($data['priority'] ?? -2)
                && (int) ($have['weight'] ?? -1) === (int) ($data['weight'] ?? -2)
                && (int) ($have['port'] ?? -1) === (int) ($data['port'] ?? -2)
                && strtolower(rtrim((string) ($have['target'] ?? ''), '.')) === strtolower(rtrim((string) ($data['target'] ?? ''), '.'));
        }
        if ($type === 'MX') {
            return strtolower(rtrim((string) ($existing['content'] ?? ''), '.')) === strtolower(rtrim((string) ($body['content'] ?? ''), '.'))
                && (int) ($existing['priority'] ?? 0) === (int) ($body['priority'] ?? 0);
        }
        $have = trim((string) ($existing['content'] ?? ''), '"');
        $want = trim((string) ($body['content'] ?? ''), '"');

        return $have === $want;
    }

    /**
     * @param array<string, mixed> $zone
     *
     * @return array{id: string, name: string, status: string, name_servers: list<string>}
     */
    private function mapZone(array $zone): array
    {
        // Unwrap { success, result: {…} }
        if (isset($zone['result']) && is_array($zone['result']) && isset($zone['result']['id'])) {
            $zone = $zone['result'];
        }
        $ns = $zone['name_servers'] ?? $zone['nameServers'] ?? [];
        if (!is_array($ns)) {
            $ns = [];
        }
        $ns = array_values(array_filter(array_map(static fn ($n) => strtolower((string) $n), $ns)));

        return [
            'id' => (string) ($zone['id'] ?? ''),
            'name' => (string) ($zone['name'] ?? ''),
            'status' => (string) ($zone['status'] ?? ''),
            'name_servers' => $ns,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->httpHandler !== null) {
            $raw = ($this->httpHandler)($method, $path, $body);
            if (!is_array($raw)) {
                throw new RuntimeException('Cloudflare HTTP handler returned non-array.');
            }

            return $raw;
        }

        $url = self::API_BASE . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to init curl for Cloudflare API.');
        }
        $headers = [
            'Authorization: Bearer ' . $this->config['api_token'],
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('Cloudflare API transport error: ' . $err);
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Cloudflare API returned invalid JSON (HTTP ' . $code . ').');
        }
        if ($code >= 400 || (isset($decoded['success']) && $decoded['success'] === false)) {
            $errors = $decoded['errors'] ?? [];
            $msg = is_array($errors) && $errors !== []
                ? (string) ($errors[0]['message'] ?? json_encode($errors))
                : ('HTTP ' . $code);
            if (is_object($this->log) && method_exists($this->log, 'error')) {
                $this->log->error('Cloudflare API error on ' . $method . ' ' . $path . ': ' . $msg);
            }
            throw new RuntimeException('Cloudflare API error: ' . $msg);
        }

        // Normalise: return full payload so list endpoints keep result_info.
        return $decoded;
    }

    private function truthy(mixed $value): bool
    {
        return self::truthyStatic($value);
    }

    private static function truthyStatic(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        $s = strtolower(trim((string) $value));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
