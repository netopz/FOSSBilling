<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

describe('Server_Manager_Openpanel DKIM zone parsing', function (): void {
    function buildOpenPanelManager(): Server_Manager_Openpanel
    {
        return new Server_Manager_Openpanel([
            'host' => 'pluto.example.com',
            'username' => 'admin',
            'password' => 'secret',
            'ip' => '203.0.113.10',
            'secure' => true,
            'port' => '2087',
        ]);
    }

    test('extractDkimTxtFromBindZone flattens single-line TXT', function (): void {
        $zone = <<<'ZONE'
$TTL 1h
@ IN A 203.0.113.10
mail._domainkey    14400     IN      TXT     "v=DKIM1; h=sha256; k=rsa; p=ABC123"
_dmarc IN TXT "v=DMARC1; p=none;"
ZONE;
        $manager = buildOpenPanelManager();
        expect($manager->extractDkimTxtFromBindZone($zone))
            ->toBe('v=DKIM1; h=sha256; k=rsa; p=ABC123');
    });

    test('extractDkimTxtFromBindZone flattens multi-string TXT', function (): void {
        $zone = <<<'ZONE'
mail._domainkey IN TXT "v=DKIM1; h=sha256; k=rsa; " "p=AAA" "BBB"
ZONE;
        $manager = buildOpenPanelManager();
        expect($manager->extractDkimTxtFromBindZone($zone))
            ->toBe('v=DKIM1; h=sha256; k=rsa; p=AAABBB');
    });

    test('extractDkimTxtFromBindZone returns null when missing', function (): void {
        $manager = buildOpenPanelManager();
        expect($manager->extractDkimTxtFromBindZone('@ IN A 1.2.3.4'))->toBeNull();
    });
});
