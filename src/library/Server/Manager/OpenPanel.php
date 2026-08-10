<?php

/**
 * Copyright 2022-2024 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Random\RandomException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * OpenPanel API.
 * @see https://dev.openpanel.com/api/
 */
class Server_Manager_Openpanel extends Server_Manager
{
    /**
     * Returns the form configuration for the OpenPanel server manager.
     *
     * @return array the form configuration as an associative array
     */
    public static function getForm(): array
    {
        return [
            'label' => 'OpenPanel',
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'username',
                            'type' => 'text',
                            'label' => 'Username',
                            'placeholder' => 'Username used to login to OpenAdmin',
                            'required' => true,
                        ],
                        [
                            'name' => 'password',
                            'type' => 'password',
                            'label' => 'Password',
                            'placeholder' => 'Password for the OpenAdmin user',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Initializes the OpenPanel server manager.
     * Checks if the necessary configuration options are set and throws an exception if any are missing.
     *
     * @throws Server_Exception if any necessary configuration options are missing
     */
    public function init(): void
    {
        if (empty($this->_config['host'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => 'OpenPanel', ':missing' => 'hostname'], 2001);
        }

        if (empty($this->_config['username'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => 'OpenPanel', ':missing' => 'username'], 2001);
        }

        if (empty($this->_config['password']) && empty($this->_config['accesshash'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => 'OpenPanel', ':missing' => 'authentication credentials'], 2001);
        }

        // If port not set, use OpenPanel default.
        $this->_config['port'] = empty($this->_config['port']) ? '2087' : $this->_config['port'];
    }










function getAuthToken() {
    $apiProtocol = $this->_config['secure'] ? 'https://' : 'http://';
    $host = $this->_config['host'];
    $username = $this->_config['username'];
    $password = $this->_config['password'];
    $port = $this->getPort();
    $authEndpoint = "{$apiProtocol}{$host}:{$port}/api/";

    //error_log("Username: $username");

    $postData = json_encode(array(
        'username' => $username,
        'password' => $password
    ));

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
        CURLOPT_TIMEOUT => 10,
    ));

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    //error_log("HTTP Status Code: $httpCode");

    if (curl_errno($curl)) {
        $error = "cURL Error: " . curl_error($curl);
        error_log($error);
        $token = false;
    } else {
        //error_log("Raw Response: $response");
        $responseData = json_decode($response, true);
        //error_log("Decoded response: " . print_r($responseData, true));
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Decode Error: " . json_last_error_msg());
            curl_close($curl);
            return false;
        }

        $token = isset($responseData['access_token']) ? $responseData['access_token'] : false;
        
        if (!$token) {
            error_log("API is working, but JWT not found in response!");
        }
        //error_log("JWT: " . $token);
    }

    curl_close($curl);
    return $token;
}

    
    

function makeApiRequest($endpoint, $data = null, $method = 'GET') {
    $apiProtocol = $this->_config['secure'] ? 'https://' : 'http://';
    $host = $this->_config['host'];
    $baseUrl = $apiProtocol . $host . ':' . $this->getPort() . '/api/';

    $url = $baseUrl . $endpoint;

    error_log("URL: $url");

    $token = $this->getAuthToken();
        
    if (!$token) {
        error_log("Failed to retrieve auth token");
        return false;
    }
  
    $curl = curl_init();
  
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $data,
        //CURLOPT_RESOLVE => array("HOST_HERE:PORT_HERE:IP_HERE"),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
}

      















    
    /**
     * Returns the login URL for a OpenPanel account.
     *
     * @param Server_Account|null $account The account for which to get the login URL. This parameter is currently not used.
     *
     * @return string the login URL
     */
    public function getLoginUrl(Server_Account $account = null): string
    {
        $host = $this->_config['host'];
        $protocol = $this->_config['secure'] ? 'https://' : 'http://';

        return $protocol . $host . ':2083';
    }

    /**
     * Returns the login URL for a OpenAdmin reseller account.
     *
     * @param Server_Account|null $account The account for which to get the login URL. This parameter is currently not used.
     *
     * @return string the login URL
     */
    public function getResellerLoginUrl(Server_Account $account = null): string
    {
        $host = $this->_config['host'];
        $protocol = $this->_config['secure'] ? 'https://' : 'http://';
        $url = $protocol . $this->_config['host'] . ':' . $this->getPort() . '/api/';

        return $protocol . $host . ':2087';
    }

    # OpenAdmin can use custom port
    public function getPort(): int|string
    {
        $port = $this->_config['port'];

        if (filter_var($port, FILTER_VALIDATE_INT) !== false && $port >= 0 && $port <= 65535) {
            return $this->_config['port'];
        } else {
            return 2087;
        }
    }



    
    /**
     * Tests the connection to the OpenPanel server.
     * Sends a request to the OpenPanel server to check if api is working
     *
     * @return true if the connection was successful
     *
     * @throws Server_Exception if an error occurs during the request
     */
public function testConnection(): bool
{
    // Construct the request URL directly
    $apiProtocol = $this->_config['secure'] ? 'https://' : 'http://';
    $host = $this->_config['host'];
    $baseUrl = $apiProtocol . $host . ':' . $this->getPort() . '/api/';
    $url = $baseUrl;  // As no endpoint is provided, it's just the base URL

    // Make the API request
    $response = $this->makeApiRequest(null);
        
    if (!$response) {
        // Log the error and include the URL in the exception message
        throw new Server_Exception("Can't connect to $url Possible invalid credentials or unreachable host.");
    }

    $decoded = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Server_Exception("Can't connect to the server: Invalid JSON - " . json_last_error_msg() . ". Response was: " . $response);
    }

    if (isset($decoded->message) && $decoded->message === "API is working!") {
        return true;
    }

    $errorMessage = isset($decoded->message) ? $decoded->message : 'Unexpected API response';
    throw new Server_Exception("Can't connect to the server: {$errorMessage}. Full response: " . json_encode($decoded));
}


    /**
     * Generates a username for a new account on the OpenPanel server.
     *
     * Must be unique across the panel. The previous scheme (7 domain chars +
     * one digit) only had ~10 variants per domain prefix and collided under
     * parallel E2E / same-domain orders.
     *
     * Format: vf{domainSlug}{8 hex} — lowercase alphanumeric, ≤32 chars.
     *
     * @param string $domain the domain name for which to generate a username
     *
     * @return string the generated username
     *
     * @throws RandomException if an error occurs during the generation of a random number
     */
    public function generateUsername(string $domain): string
    {
        $processedDomain = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $domain));
        // Avoid a leading digit in the slug so the final name stays POSIX-friendly
        // even if the vf prefix is ever removed.
        $slug = substr(ltrim($processedDomain, '0123456789'), 0, 6);
        if ($slug === '') {
            $slug = 'u' . bin2hex(random_bytes(2));
        }

        // 8 hex chars ≈ 4.3e9 space — enough that same-domain parallel creates
        // do not collide in practice.
        $username = 'vf' . $slug . bin2hex(random_bytes(4));

        // OpenPanel rejects usernames starting with "test".
        if (str_starts_with($username, 'test')) {
            $username = 'a' . substr($username, 1);
        }

        return substr($username, 0, 32);
    }

    /**
     * Synchronizes an account with the OpenPanel server.
     * Sends a request to the OpenPanel server to get the account's details and updates the Server_Account object accordingly.
     *
     * @param Server_Account $account the account to be synchronized
     *
     * @return Server_Account the updated account
     *
     * @throws Server_Exception if an error occurs during the request, or if the account does not exist on the OpenPanel server
     */
    public function synchronizeAccount(Server_Account $account)
    {
       return false;
    }

    /**
     * Creates a new account on the OpenPanel server.
     * Sends a request to the OpenPanel server to create a new account with the details provided in the Server_Account object.
     * If the account is a reseller account, it also sets up the reseller and assigns the appropriate ACL list.
     *
     * @param Server_Account $account The account to be created. This object should contain all the necessary details for the new account.
     *
     * @return bool returns true if the account was successfully created, false otherwise
     *
     * @throws Server_Exception if an error occurs during the request, or if the response from the OpenPanel server indicates an error
     */
    public function createAccount(Server_Account $account)
    {
        $client = $account->getClient();
        $package = $account->getPackage();
        $this->getLog()->info('Creating account ' . $account->getUsername());
        $data = json_encode(array(
            "email" => $client->getEmail(),
            'username' => $account->getUsername(),
            'password' => $account->getPassword(),
            "plan_name" => $package->getName()

        ));
    

        $rawResponse = $this->makeApiRequest("users", $data, 'POST');
        $response = json_decode($rawResponse);
    
        $created = false;
        if (is_object($response) && !empty($response->success)) {
            $created = true;
        }
    
        // https://github.com/stefanpejcic/FOSSBilling-OpenPanel/issues/2
        if (!$created && strpos($rawResponse, 'Successfully added user') !== false) {
            $created = true;
        }

        if (!$created) {
            $errorMsg = is_object($response) && isset($response->error) ? $response->error : $rawResponse;
            throw new Server_Exception('Error when creating ' . $account->getUsername() . ': ' . $errorMsg);
        }

        // Attach the order domain to the new user (FOSS createAccount historically
        // only created the user). Failures are logged; Synergy DNS still proceeds.
        $domain = trim((string) $account->getDomain());
        if ($domain !== '') {
            try {
                $domainPayload = json_encode([
                    'username' => $account->getUsername(),
                    'domain' => $domain,
                    'docroot' => '/var/www/html/' . $domain,
                ]);
                $domainResponse = $this->makeApiRequest('domains/new', $domainPayload, 'POST');
                $this->getLog()->info('OpenPanel domain create response for ' . $domain . ': ' . substr((string) $domainResponse, 0, 300));
                // Ensure OpenDKIM public TXT is visible via GET /domains/{domain}/dns
                // so Synergy external DNS apply can pick it up automatically.
                $this->tryPublishDkimZone($domain);
            } catch (\Throwable $e) {
                $this->getLog()->error('OpenPanel domain attach failed for ' . $domain . ': ' . $e->getMessage());
            }
        }

        // Hostinger-style plan → lean stack / FPM caps (best-effort; never fail order).
        $this->tryApplyStackProfile($account->getUsername(), (string) $package->getName());

        return true;
    }

    /**
     * Best-effort: run Pluto helper over SSH so the OpenPanel BIND zone includes
     * mail._domainkey (OpenPanel generates keys on disk but often omits them from
     * the zone when using external DNS). Safe no-op when SSH is unavailable.
     *
     * Uses a dedicated key for the PHP/FPM user:
     *   /var/www/.ssh/id_ed25519_openpanel_dkim
     * Override with server config key `dkim_ssh_key` if needed.
     */
    private function tryPublishDkimZone(string $domain): void
    {
        $this->runPlutoHelper('vioflare-publish-dkim-zone', [$domain], 'DKIM zone publish');
    }

    /**
     * Best-effort: after DNS points at Pluto, hit https://domain to trigger
     * Caddy AutoSSL (on_demand Let's Encrypt). Safe no-op when SSH/DNS unavailable.
     */
    public function tryIssueSsl(string $domain): void
    {
        $this->runPlutoHelper('vioflare-trigger-ssl', [$domain], 'AutoSSL trigger');
    }

    /**
     * Best-effort: map catalog plan → lean OpenPanel stack / PHP-FPM caps on Pluto.
     */
    private function tryApplyStackProfile(string $username, string $planName): void
    {
        $this->runPlutoHelper(
            'vioflare-apply-stack-profile',
            [$username, $planName],
            'Stack profile apply'
        );
    }

    /**
     * Run an allow-listed helper on Pluto via the www-data DKIM SSH key /
     * forced-command wrapper.
     *
     * @param list<string> $args helper arguments (already validated per helper)
     */
    private function runPlutoHelper(string $helper, array $args, string $label): void
    {
        $allowed = [
            'vioflare-publish-dkim-zone' => 1,
            'vioflare-trigger-ssl' => 1,
            'vioflare-apply-stack-profile' => 2,
        ];
        if (!isset($allowed[$helper]) || count($args) !== $allowed[$helper]) {
            return;
        }

        $validated = [];
        if ($helper === 'vioflare-apply-stack-profile') {
            $username = strtolower(trim((string) ($args[0] ?? '')));
            $planName = trim((string) ($args[1] ?? ''));
            if ($username === '' || !preg_match('/^[a-z0-9_-]+$/', $username)) {
                return;
            }
            if ($planName === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $planName)) {
                return;
            }
            $validated = [$username, $planName];
        } else {
            $domain = strtolower(trim((string) ($args[0] ?? '')));
            if ($domain === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain)) {
                return;
            }
            $validated = [$domain];
        }

        $subject = implode(' ', $validated);

        $ip = trim((string) ($this->_config['ip'] ?? ''));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $host = trim((string) ($this->_config['host'] ?? ''));
            if ($host !== '') {
                $resolved = gethostbyname($host);
                if (is_string($resolved) && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                    $ip = $resolved;
                }
            }
        }
        if ($ip === '') {
            return;
        }

        $keyPath = trim((string) ($this->_config['dkim_ssh_key'] ?? ''));
        if ($keyPath === '') {
            $keyPath = '/var/www/.ssh/id_ed25519_openpanel_dkim';
        }
        if (!is_readable($keyPath)) {
            $this->getLog()->info($label . ' skipped for ' . $subject . ': SSH key not readable at ' . $keyPath);

            return;
        }

        $knownHosts = dirname($keyPath) . '/known_hosts';
        // Args already validated; do not quote-wrap (forced-command wrappers see
        // the literal SSH_ORIGINAL_COMMAND including quotes).
        $remote = '/usr/local/bin/' . $helper . ' ' . implode(' ', $validated);
        $sshOpts = [
            '-i ' . escapeshellarg($keyPath),
            '-o IdentitiesOnly=yes',
            '-o BatchMode=yes',
            // SSL trigger / stack profile may wait on compose or DNS + ACME.
            '-o ConnectTimeout=8',
            '-o ServerAliveInterval=15',
            '-o StrictHostKeyChecking=accept-new',
        ];
        if (is_readable($knownHosts)) {
            $sshOpts[] = '-o UserKnownHostsFile=' . escapeshellarg($knownHosts);
        }
        $cmd = sprintf(
            'ssh %s %s %s 2>&1',
            implode(' ', $sshOpts),
            escapeshellarg('root@' . $ip),
            escapeshellarg($remote)
        );

        $output = [];
        $code = 0;
        @exec($cmd, $output, $code);
        if ($code !== 0) {
            $this->getLog()->info($label . ' skipped/failed for ' . $subject . ': ' . implode(' ', $output));
        } else {
            $this->getLog()->info($label . ' OK for ' . $subject . ': ' . implode(' ', $output));
        }
    }

    /**
     * Read the OpenDKIM public TXT value OpenPanel expects for external DNS (Synergy).
     * Prefers the panel BIND zone (published on domain create); falls back to
     * deliverability payloads when the API returns structured DKIM data.
     */
    public function getDkimPublicTxt(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            if ($attempt > 0) {
                usleep(500_000);
            }

            $fromZone = $this->getDkimPublicTxtFromZone($domain);
            if ($fromZone !== null) {
                return $fromZone;
            }

            $fromDeliverability = $this->getDkimPublicTxtFromDeliverability($domain);
            if ($fromDeliverability !== null) {
                return $fromDeliverability;
            }
        }

        return null;
    }

    private function getDkimPublicTxtFromZone(string $domain): ?string
    {
        $raw = $this->makeApiRequest('domains/' . rawurlencode($domain) . '/dns');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $content = (string) ($data['content'] ?? '');
        if ($content === '') {
            return null;
        }

        return $this->extractDkimTxtFromBindZone($content);
    }

    private function getDkimPublicTxtFromDeliverability(string $domain): ?string
    {
        $raw = $this->makeApiRequest('emails/deliverability/' . rawurlencode($domain));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $candidates = [
            $data['dkim']['expected'] ?? null,
            $data['dkim']['current'] ?? null,
            $data['expected']['dkim'] ?? null,
            $data['dkim_txt'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && str_starts_with(trim($candidate), 'v=DKIM1')) {
                return trim($candidate, " \t\"'");
            }
        }

        return null;
    }

    /**
     * Extract a flattened DKIM TXT value from an OpenPanel/BIND zone file body.
     */
    public function extractDkimTxtFromBindZone(string $zoneContent): ?string
    {
        // Single-line TXT: mail._domainkey ... "v=DKIM1; ..."
        if (preg_match('/mail\._domainkey[^\n]*\sIN\s+TXT\s+("(?:\\\\.|[^"\\\\])*"(?:\s*"(?:\\\\.|[^"\\\\])*")*)/i', $zoneContent, $m)) {
            return $this->flattenBindTxtRdata($m[1]);
        }
        // Multi-line TXT with parentheses
        if (preg_match('/mail\._domainkey[^\n]*\sIN\s+TXT\s+\((.*?)\)/is', $zoneContent, $m)) {
            return $this->flattenBindTxtRdata($m[1]);
        }
        // Parentheses form used by opendkim mail.txt pasted into zones
        if (preg_match('/mail\._domainkey[^\n]*\sTXT\s+\((.*?)\)/is', $zoneContent, $m)) {
            return $this->flattenBindTxtRdata($m[1]);
        }

        return null;
    }

    private function flattenBindTxtRdata(string $rdata): ?string
    {
        if (!preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/', $rdata, $parts)) {
            $flat = trim(preg_replace('/\s+/', '', $rdata) ?? '');
            $flat = trim($flat, " \t\"'");

            return str_starts_with($flat, 'v=DKIM1') ? $flat : null;
        }
        $flat = '';
        foreach ($parts[1] as $chunk) {
            $flat .= stripcslashes($chunk);
        }
        $flat = trim($flat);

        return str_starts_with($flat, 'v=DKIM1') ? $flat : null;
    }
        
        /**
     * Suspends an account on the OpenPanel server.
     *
     * @param Server_Account $account the account to be suspended
     *
     * @return bool returns true if the account was successfully suspended
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function suspendAccount(Server_Account $account): bool
    {
        // Log the suspension
        $this->getLog()->info('Suspending account ' . $account->getUsername());

        $client = $account->getClient();

        $data = json_encode(array("action" => "suspend"));
        $response = $this->makeApiRequest("users/" . $account->getUsername() , $data, 'PATCH');
        $response = json_decode($response);

        if ($response->success == 1 || $response->success ==  true ) {
            return true;    
            
        }
        
        throw new Server_Exception('Error when suspending ' . $client->getUsername() . ': ' . json_encode($response));

    }

    /**
     * Unsuspends an account on the OpenPanel server.
     *
     * @param Server_Account $account the account to be unsuspended
     *
     * @return bool returns true if the account was successfully unsuspended
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function unsuspendAccount(Server_Account $account): bool
    {
        // Log the unsuspension
        $this->getLog()->info('Activating account ' . $account->getUsername());

        $client = $account->getClient();

        $data = json_encode(array("action" => "unsuspend"));
        $response = $this->makeApiRequest("users/". $account->getUsername() , $data, 'PATCH');
        $response = json_decode($response);

        if ($response->success == 1 || $response->success ==  true ) {
            return true;    
        
        }


        throw new Server_Exception('Failed to  unsuspend ' . $client->getUsername() . ': ' . $response->error);

    }

    /**
     * Cancels an account on the OpenPanel server.
     *
     * @param Server_Account $account the account to be cancelled
     *
     * @return bool returns true if the account was successfully cancelled
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function cancelAccount(Server_Account $account): bool
    {
        // Log the cancellation
        $this->getLog()->info('Canceling account ' . $account->getUsername());

        $response = $this->makeApiRequest(endpoint: "users/". $account->getUsername(), method: 'DELETE');
        $response = json_decode($response);

        if ($response->success) {
            return true;    
        
        }
        $client = $account->getClient();


        throw new Server_Exception('Failed to  canceling ' . $client->getUsername() . ': ' . $response->error);

    }

    /**
     * Changes the package of an account on the OpenPanel server.
     *
     * @param Server_Account $account the account for which to change the package
     * @param Server_Package $package the new package
     *
     * @return bool returns true if the package was successfully changed
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function changeAccountPackage(Server_Account $account, Server_Package $package)
    {
        
        // Log the package change
        $this->getLog()->info('Changing account ' . $account->getUsername() . ' package');
        $data = json_encode(array("plan_name" => $package->getName()));

        $response = $this->makeApiRequest("users/". $account->getUsername(),$data   , 'PUT');
        $response = json_decode($response);

        if ($response->success) {
            $this->tryApplyStackProfile($account->getUsername(), (string) $package->getName());

            return true;
        }
        $client = $account->getClient();


        throw new Server_Exception('Failed to change package for user ' . $client->getUsername() . ' | Error: ' . $response->error);
    }

    /**
     * Changes the password of an account on the OpenPanel server.
     *
     * @param Server_Account $account     the account for which to change the password
     * @param string         $newPassword the new password
     *
     * @return bool returns true if the password was successfully changed
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function changeAccountPassword(Server_Account $account, string $newPassword)
    {
        // Log the password change
        $this->getLog()->info('Changing account ' . $account->getUsername() . ' password');

        $data = json_encode(array("password" => $newPassword));

        $response = $this->makeApiRequest("users/". $account->getUsername(),$data   , 'PATCH');
        $response = json_decode($response);

        if ($response->success) {
            return true;    
        
        }
        $client = $account->getClient();


        throw new Server_Exception('Failed to change package for user ' . $client->getUsername() . ' | Error: ' . $response->error);
    }

    /**
     * Changes the username of an account on the OpenPanel server.
     *
     * @param Server_Account $account     the account for which to change the username
     * @param string         $newUsername the new username
     *
     * @return bool returns true if the username was successfully changed
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function changeAccountUsername(Server_Account $account, string $newUsername): bool
    {

        throw new Server_Exception('Error: Changing username is not enabled on OpenPanel server.');
    }

    /**
     * Changes the domain of an account on the OpenPanel server.
     *
     * @param Server_Account $account   the account for which to change the domain
     * @param string         $newDomain the new domain
     *
     * @return bool returns true if the domain was successfully changed
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function changeAccountDomain(Server_Account $account, string $newDomain): bool
    {
        throw new Server_Exception('Error: OpenPanel does not have a concept of primary domains.');

    }

    /**
     * Changes the IP of an account on the OpenPanel server.
     *
     * @param Server_Account $account the account for which to change the IP
     * @param string         $newIp   the new IP
     *
     * @return bool returns true if the IP was successfully changed
     *
     * @throws Server_Exception if an error occurs during the request
     */
    public function changeAccountIp(Server_Account $account, string $newIp): bool
    {
        throw new Server_Exception('OpenPanel does not supporting change account IP');

    }



  

   
}
