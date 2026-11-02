#!/usr/bin/env php
<?php
/**
 * Configure FOSSBilling tax rules for Vioflare:
 *   - Enable tax support
 *   - Delete global (empty-country) non-zero catch-alls
 *   - Delete AU rules with rate > 0 (not GST-registered → net = gross)
 *   - Upsert 19% MwSt for every EU member state
 *
 * Usage:
 *   FOSSBILLING_URL=https://billing.vioflare.com FOSSBILLING_ADMIN_API_TOKEN=... \
 *     php tools/vioflare-tax-seed.php
 *   php tools/vioflare-tax-seed.php --dry-run --url=https://billing.vioflare.com
 *
 * Env aliases: APP_URL / TEST_API_KEY (same as demo-seed).
 */
declare(strict_types=1);

$options = getopt('', ['url:', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: vioflare-tax-seed.php [--url=...] [--dry-run]\n";
    echo "Env: FOSSBILLING_URL or APP_URL, FOSSBILLING_ADMIN_API_TOKEN or TEST_API_KEY\n";
    exit(0);
}

$baseUrl = rtrim((string) (
    $options['url']
    ?? getenv('FOSSBILLING_URL')
    ?: getenv('APP_URL')
    ?: 'https://billing.vioflare.com'
), '/');
$apiKey = (string) (getenv('FOSSBILLING_ADMIN_API_TOKEN') ?: getenv('TEST_API_KEY') ?: '');
$dryRun = isset($options['dry-run']);

if ($apiKey === '') {
    fwrite(STDERR, "Missing API token. Set FOSSBILLING_ADMIN_API_TOKEN or TEST_API_KEY.\n");
    exit(2);
}

$eu = [
    'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
    'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
    'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
];

function api(string $endpoint, array $params = []): mixed
{
    global $baseUrl, $apiKey;

    $url = $baseUrl . '/api/' . ltrim($endpoint, '/');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "admin:{$apiKey}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    $out = curl_exec($ch);
    if ($out === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL failed for {$endpoint}: {$err}");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $out, true);
    if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
        throw new RuntimeException("Invalid JSON from {$endpoint} (HTTP {$code}): " . substr((string) $out, 0, 300));
    }
    if (!empty($decoded['error'])) {
        $msg = $decoded['error']['message'] ?? 'Unknown API error';
        throw new RuntimeException("API {$endpoint} failed: {$msg}");
    }

    return $decoded['result'];
}

function note(string $msg): void
{
    echo $msg . PHP_EOL;
}

note(($dryRun ? '[dry-run] ' : '') . "Vioflare tax seed → {$baseUrl}");

// Enable tax support via system params when possible
try {
    $params = api('admin/system/get_params', []);
    $enabled = $params['tax_enabled'] ?? null;
    note('tax_enabled currently: ' . var_export($enabled, true));
    if (!$dryRun) {
        // FOSS system update_params varies by version; try common keys.
        try {
            api('admin/system/update_params', ['tax_enabled' => 1]);
            note('Enabled tax_enabled=1');
        } catch (Throwable $e) {
            note('WARN: could not set tax_enabled via API — enable Tax Support in Invoice → Tax → Settings. ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    note('WARN: get_params failed: ' . $e->getMessage());
}

$list = api('admin/invoice/tax_get_list', ['per_page' => 500]);
$rows = $list['list'] ?? (is_array($list) ? $list : []);
if (!is_array($rows)) {
    $rows = [];
}

foreach ($rows as $row) {
    $id = (int) ($row['id'] ?? 0);
    $country = strtoupper(trim((string) ($row['country'] ?? '')));
    $rate = (float) ($row['taxrate'] ?? 0);
    $name = (string) ($row['name'] ?? '');

    $delete = false;
    if ($country === '' && $rate > 0) {
        note("DELETE global id={$id} name={$name} rate={$rate}");
        $delete = true;
    } elseif ($country === 'AU' && $rate > 0) {
        note("DELETE AU id={$id} name={$name} rate={$rate}");
        $delete = true;
    }

    if ($delete && !$dryRun && $id) {
        api('admin/invoice/tax_delete', ['id' => $id]);
    }
}

// Refresh list after deletes
$list = api('admin/invoice/tax_get_list', ['per_page' => 500]);
$rows = $list['list'] ?? (is_array($list) ? $list : []);
$byCountry = [];
foreach ($rows as $row) {
    $c = strtoupper(trim((string) ($row['country'] ?? '')));
    if ($c !== '') {
        $byCountry[$c] = $row;
    }
}

foreach ($eu as $cc) {
    if (isset($byCountry[$cc])) {
        $row = $byCountry[$cc];
        $rate = (float) ($row['taxrate'] ?? 0);
        $state = trim((string) ($row['state'] ?? ''));
        $id = (int) ($row['id'] ?? 0);
        if (abs($rate - 19.0) > 0.01 || $state !== '') {
            note("UPDATE {$cc} id={$id} → 19% MwSt");
            if (!$dryRun) {
                api('admin/invoice/tax_update', [
                    'id' => $id,
                    'name' => 'MwSt',
                    'taxrate' => 19,
                    'country' => $cc,
                    'state' => '',
                ]);
            }
        } else {
            note("OK {$cc} already 19% MwSt");
        }
        continue;
    }
    note("CREATE {$cc} 19% MwSt");
    if (!$dryRun) {
        api('admin/invoice/tax_create', [
            'name' => 'MwSt',
            'taxrate' => 19,
            'country' => $cc,
        ]);
    }
}

note('Done.');
note('Bust middleware cache: POST /api/v1/hosting/admin/refresh with X-Admin-Key');
note('Verify: GET /api/v1/billing/admin/tax-diagnostics with X-Admin-Key');
