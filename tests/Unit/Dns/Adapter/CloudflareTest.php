<?php

/**
 * Copyright 2022-2026 FOSSBilling / Vioflare
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

function buildCloudflareAdapter(callable $handler): Dns_Adapter_Cloudflare
{
    $adapter = new Dns_Adapter_Cloudflare([
        'api_token' => 'test-token',
        'account_id' => 'acct-1',
        'enabled' => true,
    ]);
    $adapter->setHttpHandler($handler);

    return $adapter;
}

describe('Dns_Adapter_Cloudflare', function (): void {
    test('ensureZone returns existing zone without creating', function (): void {
        $calls = [];
        $adapter = buildCloudflareAdapter(function (string $method, string $path, ?array $body) use (&$calls): array {
            $calls[] = [$method, $path, $body];
            if (str_starts_with($path, '/zones?')) {
                return [
                    'success' => true,
                    'result' => [[
                        'id' => 'zone-1',
                        'name' => 'example.com.au',
                        'status' => 'active',
                        'name_servers' => ['a.ns.cloudflare.com', 'b.ns.cloudflare.com'],
                    ]],
                ];
            }
            throw new RuntimeException('unexpected ' . $method . ' ' . $path);
        });

        $zone = $adapter->ensureZone('example.com.au');
        expect($zone['id'])->toBe('zone-1');
        expect($zone['name_servers'])->toHaveCount(2);
        expect($calls)->toHaveCount(1);
        expect($calls[0][0])->toBe('GET');
    });

    test('applyWebDns upserts proxied apex and www A records', function (): void {
        $posts = [];
        $adapter = buildCloudflareAdapter(function (string $method, string $path, ?array $body) use (&$posts): array {
            if (str_starts_with($path, '/zones?')) {
                return [
                    'success' => true,
                    'result' => [[
                        'id' => 'zone-1',
                        'name' => 'example.com.au',
                        'status' => 'active',
                        'name_servers' => ['a.ns.cloudflare.com', 'b.ns.cloudflare.com'],
                    ]],
                ];
            }
            if (str_contains($path, '/dns_records') && $method === 'GET') {
                return ['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]];
            }
            if (str_contains($path, '/dns_records') && $method === 'POST') {
                $posts[] = $body;
                return [
                    'success' => true,
                    'result' => ['id' => 'rec-' . count($posts)],
                ];
            }
            throw new RuntimeException('unexpected ' . $method . ' ' . $path);
        });

        $out = $adapter->applyWebDns('example.com.au', '173.249.33.154', '2a02:c207:3019:5586::1');
        expect($out)->toHaveKeys(['apex_a', 'www_a', 'apex_aaaa', 'www_aaaa']);
        expect($posts)->toHaveCount(4);
        foreach ($posts as $body) {
            expect($body['proxied'])->toBeTrue();
        }
    });

    test('applyMailDns keeps mail records DNS-only (not proxied)', function (): void {
        $posts = [];
        $adapter = buildCloudflareAdapter(function (string $method, string $path, ?array $body) use (&$posts): array {
            if (str_starts_with($path, '/zones?')) {
                return [
                    'success' => true,
                    'result' => [[
                        'id' => 'zone-1',
                        'name' => 'example.com.au',
                        'status' => 'active',
                        'name_servers' => ['a.ns.cloudflare.com', 'b.ns.cloudflare.com'],
                    ]],
                ];
            }
            if (str_contains($path, '/dns_records') && $method === 'GET') {
                return ['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]];
            }
            if (str_contains($path, '/dns_records') && $method === 'POST') {
                $posts[] = $body;
                return ['success' => true, 'result' => ['id' => 'rec-' . count($posts)]];
            }
            throw new RuntimeException('unexpected ' . $method . ' ' . $path);
        });

        $adapter->applyMailDns('example.com.au', '173.249.33.154', 'v=DKIM1; k=rsa; p=ABC');
        expect($posts)->not->toBeEmpty();
        foreach ($posts as $body) {
            if (in_array($body['type'] ?? '', ['A', 'AAAA', 'CNAME'], true)) {
                expect($body['proxied'])->toBeFalse();
            }
        }
        $types = array_column($posts, 'type');
        expect($types)->toContain('MX');
        expect($types)->toContain('TXT');
        expect($types)->toContain('SRV');
    });

    test('skip_mx omits MX SPF DMARC in applyHostingDns mail set', function (): void {
        $posts = [];
        $adapter = buildCloudflareAdapter(function (string $method, string $path, ?array $body) use (&$posts): array {
            if (str_starts_with($path, '/zones?')) {
                return [
                    'success' => true,
                    'result' => [[
                        'id' => 'zone-1',
                        'name' => 'example.com.au',
                        'status' => 'active',
                        'name_servers' => ['a.ns.cloudflare.com', 'b.ns.cloudflare.com'],
                    ]],
                ];
            }
            if (str_contains($path, '/dns_records') && $method === 'GET') {
                return ['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]];
            }
            if (str_contains($path, '/dns_records') && $method === 'POST') {
                $posts[] = $body;
                return ['success' => true, 'result' => ['id' => 'rec-' . count($posts)]];
            }
            throw new RuntimeException('unexpected ' . $method . ' ' . $path);
        });

        $adapter->applyHostingDns('example.com.au', '173.249.33.154', ['skip_mx' => true]);
        $types = array_column($posts, 'type');
        expect($types)->not->toContain('MX');
    });
});
