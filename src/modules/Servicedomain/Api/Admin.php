<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicedomain\Api;

use Box\Mod\Servicedomain\Entity\ServiceDomain;
use Box\Mod\Servicedomain\Entity\Tld;
use Box\Mod\Servicedomain\Entity\TldRegistrar;
use FOSSBilling\PaginationOptions;
use FOSSBilling\Validation\Api\RequiredParams;

/**
 * Domain order management.
 */
class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Update domain service.
     * Does not send actions to domain registrar. Used to sync domain details
     * on FOSSBilling.
     *
     * @optional string $ns1 - 1 Nameserver hostname, ie: ns1.mydomain.com
     * @optional string $ns2 - 2 Nameserver hostname, ie: ns2.mydomain.com
     * @optional string $ns3 - 3 Nameserver hostname, ie: ns3.mydomain.com
     * @optional string $ns4 - 4 Nameserver hostname, ie: ns4.mydomain.com
     * @optional int $period - domain registration years
     * @optional bool $privacy - flag to define if domain privacy protection is enabled/disabled
     * @optional bool $locked - flag to define if domain is locked or not
     * @optional string $transfer_code - domain EPP code
     *
     * @return bool
     */
    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    public function update($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->updateDomain($s, $data);
    }

    /**
     * Update domain nameservers.
     *
     * @optional string $ns3 - 3 Nameserver hostname, ie: ns3.mydomain.com
     * @optional string $ns4 - 4 Nameserver hostname, ie: ns4.mydomain.com
     *
     * @return bool
     */
    public function update_nameservers($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->updateNameservers($s, $data);
    }

    /**
     * Update domain contact details.
     *
     * @return bool
     */
    public function update_contacts($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->updateContacts($s, $data);
    }

    /**
     * Enable domain privacy protection.
     *
     * @return bool
     */
    public function enable_privacy_protection($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->enablePrivacyProtection($s);
    }

    /**
     * Disable domain privacy protection.
     *
     * @return bool
     */
    public function disable_privacy_protection($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->disablePrivacyProtection($s);
    }

    /**
     * Get domain transfer code.
     *
     * @return bool
     */
    public function get_transfer_code($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->getTransferCode($s);
    }

    /**
     * Lock domain.
     *
     * @return bool
     */
    public function lock($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->lock($s);
    }

    /**
     * Unlock domain.
     *
     * @return bool
     */
    public function unlock($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $s = $this->_getService($data);

        return $this->getService()->unlock($s);
    }

    /**
     * Get paginated top level domains list.
     *
     * @return array
     */
    public function tld_get_list($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');
        $query = $this->getService()->tldGetSearchQuery($data);

        return $this->getDi()['pager']->paginateMappedQuery(
            $query,
            PaginationOptions::fromArray($data),
            fn (Tld $tld): array => $this->getService()->tldToApiArray($tld, $this->identity),
        );
    }

    /**
     * Get top level domain details.
     *
     * @return array
     *
     * @throws \FOSSBilling\InformationException
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function tld_get($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $model = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$model instanceof Tld) {
            throw new \FOSSBilling\InformationException('TLD not found');
        }

        return $this->getService()->tldToApiArray($model, $this->identity);
    }

    /**
     * Get top level domain details by id.
     *
     * @return array
     *
     * @throws \FOSSBilling\InformationException
     */
    #[RequiredParams(['id' => 'ID is missing'])]
    public function tld_get_id($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $model = $this->getService()->tldFindOneById($data['id']);
        if (!$model instanceof Tld) {
            throw new \FOSSBilling\InformationException('TLD not found');
        }

        return $this->getService()->tldToApiArray($model, $this->identity);
    }

    /**
     * Delete top level domain.
     *
     * @return bool
     *
     * @throws \FOSSBilling\InformationException
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function tld_delete($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $normalizedTld = $this->getService()->normalizeTld($data['tld']);
        $model = $this->getService()->tldFindOneByTld($normalizedTld);

        if (!$model instanceof Tld) {
            throw new \FOSSBilling\InformationException('TLD not found');
        }
        $service_domains = $this->getDi()['em']->getConnection()->fetchAllAssociative(
            'SELECT id FROM service_domain WHERE LOWER(TRIM(TRAILING \'.\' FROM TRIM(tld))) IN (?, ?)',
            [$normalizedTld, ltrim((string) $normalizedTld, '.')],
        );
        $count = \FOSSBilling\Tools::safeCount($service_domains);
        if ($count > 0) {
            throw new \FOSSBilling\InformationException('TLD is used by :count: domains', [':count:' => $count], 707);
        }

        return $this->getService()->tldRm($model);
    }

    /**
     * Add new top level domain.
     *
     * @optional int $min_years - minimum registration period, in years
     * @optional string $periods - comma-separated list of the exact registration periods
     *                             (in years) allowed for this TLD, e.g. "1,2,3,5,10". When
     *                             omitted, any period from min_years upwards is allowed.
     *
     * @return bool
     *
     * @throws \FOSSBilling\Exception
     */
    #[RequiredParams([
        'tld' => 'TLD is missing',
        'tld_registrar_id' => 'TLD registrar ID is missing',
        'price_registration' => 'Registration price is missing',
        'price_renew' => 'Renewal price is missing',
        'price_transfer' => 'Transfer price is missing',
    ])]
    public function tld_create($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        if ($this->getService()->tldAlreadyRegistered($data['tld'])) {
            throw new \FOSSBilling\InformationException('TLD already registered');
        }

        return $this->getService()->tldCreate($data);
    }

    /**
     * Update top level domain.
     *
     * @optional int $tld_registrar_id - domain registrar id
     * @optional float $price_registration - registration price
     * @optional float $price_renew - renewal price
     * @optional float $price_transfer - transfer price
     * @optional int $min_years - minimum registration period, in years
     * @optional string $periods - comma-separated list of the exact registration periods
     *                             (in years) allowed for this TLD, e.g. "1,2,3,5,10". Pass
     *                             an empty string to clear it and allow any period from
     *                             min_years upwards again.
     *
     * @return bool
     *
     * @throws \FOSSBilling\InformationException
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function tld_update($data)
    {
        $this->checkPermissions('servicedomain', 'manage_tlds');

        $model = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$model instanceof Tld) {
            throw new \FOSSBilling\InformationException('TLD not found');
        }

        return $this->getService()->tldUpdate($model, $data);
    }

    /**
     * Get paginated registrars list.
     *
     * @return array
     */
    public function registrar_get_list($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');
        $query = $this->getService()->registrarGetSearchQuery($data);

        return $this->getDi()['pager']->paginateMappedQuery(
            $query,
            PaginationOptions::fromArray($data),
            fn (TldRegistrar $registrar): array => $this->getService()->registrarToApiArray($registrar),
        );
    }

    /**
     * Get registrars pairs.
     *
     * @return array
     */
    public function registrar_get_pairs($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        return $this->getService()->registrarGetPairs();
    }

    /**
     * Get available registrars for install.
     *
     * @return array
     */
    public function registrar_get_available($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        return $this->getService()->registrarGetAvailable();
    }

    /**
     * Install domain registrar.
     *
     * @return bool
     */
    #[RequiredParams(['code' => 'Registrar code is missing'])]
    public function registrar_install($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $code = $data['code'];
        if (!in_array($code, $this->getService()->registrarGetAvailable())) {
            throw new \FOSSBilling\Exception('Registrar is not available for installation.');
        }

        return $this->getService()->registrarCreate($data['code']);
    }

    /**
     * Uninstall domain registrar.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_delete($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $model = $this->_getRegistrar((int) $data['id']);

        return $this->getService()->registrarRm($model);
    }

    /**
     * Copy domain registrar.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_copy($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $model = $this->_getRegistrar((int) $data['id']);

        return $this->getService()->registrarCopy($model);
    }

    /**
     * Get domain registrar details.
     *
     * @return array
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_get($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $registrar = $this->_getRegistrar((int) $data['id']);

        return $this->getService()->registrarToApiArray($registrar);
    }

    /**
     * Sync domain expiration dates with registrars.
     * This action is run once a month.
     *
     * @return bool
     */
    public function batch_sync_expiration_dates($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        return $this->getService()->batchSyncExpirationDates();
    }

    /**
     * Update domain registrar.
     *
     * @optional string $title - registrar title
     * @optional array $config - registrar configuration array
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Registrar ID is missing'])]
    public function registrar_update($data)
    {
        $this->checkPermissions('servicedomain', 'manage_registrars');

        $model = $this->_getRegistrar((int) $data['id']);

        return $this->getService()->registrarUpdate($model, $data);
    }

    /**
     * List Synergy DNS zone records for a domain.
     *
     * @optional string $registrar - adapter code (default Synergy)
     *
     * @return list<array>
     */
    #[RequiredParams(['domain' => 'Domain name is missing'])]
    public function dns_list($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        return $this->_getSynergyDnsAdapter($data)->listDnsZone((string) $data['domain']);
    }

    /**
     * Apply Synergy web + mail DNS for a hosting domain (A/MX/SPF/DMARC, optional DKIM).
     *
     * @optional string $dkim_txt - OpenPanel DKIM public TXT value
     * @optional string $registrar - adapter code (default Synergy)
     *
     * @return array
     */
    #[RequiredParams([
        'domain' => 'Domain name is missing',
        'ip' => 'Server IPv4 is missing',
    ])]
    public function dns_apply_hosting($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $options = [];
        if (!empty($data['dkim_txt'])) {
            $options['dkim_txt'] = (string) $data['dkim_txt'];
        }
        if (!empty($data['ipv6'])) {
            $options['ipv6'] = (string) $data['ipv6'];
        }
        if (!empty($data['skip_mx'])) {
            $options['skip_mx'] = true;
        }
        if (!empty($data['skip_mail'])) {
            $options['skip_mail'] = true;
        }

        return $this->_getSynergyDnsAdapter($data)->applyHostingDns(
            (string) $data['domain'],
            (string) $data['ip'],
            $options,
        );
    }

    /**
     * Ensure a Cloudflare zone exists and return assigned nameservers.
     *
     * @return array{id: string, name: string, status: string, name_servers: list<string>}
     */
    #[RequiredParams(['domain' => 'Domain name is missing'])]
    public function cloudflare_zone_ensure($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        return $this->_getCloudflareDnsAdapter()->ensureZone((string) $data['domain']);
    }

    /**
     * Apply Cloudflare hosting DNS (proxied @/www, grey-cloud mail).
     *
     * @optional string $dkim_txt
     * @optional string $ipv6
     * @optional bool $skip_mx - leave existing MX (e.g. Google Workspace)
     * @optional bool $skip_mail - skip all mail/discovery records
     *
     * @return array
     */
    #[RequiredParams([
        'domain' => 'Domain name is missing',
        'ip' => 'Server IPv4 is missing',
    ])]
    public function cloudflare_dns_apply_hosting($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $options = [];
        if (!empty($data['dkim_txt'])) {
            $options['dkim_txt'] = (string) $data['dkim_txt'];
        }
        if (!empty($data['ipv6'])) {
            $options['ipv6'] = (string) $data['ipv6'];
        }
        if (!empty($data['skip_mx'])) {
            $options['skip_mx'] = true;
        }
        if (!empty($data['skip_mail'])) {
            $options['skip_mail'] = true;
        }

        $cf = $this->_getCloudflareDnsAdapter();
        $result = $cf->applyHostingDns((string) $data['domain'], (string) $data['ip'], $options);
        try {
            $cf->setSslMode((string) $data['domain'], 'full');
        } catch (\Throwable) {
            // best-effort
        }

        return $result;
    }

    /**
     * One-domain migration helper: CF zone + records, then Synergy NS → Cloudflare.
     *
     * @optional string $sld - required with tld when updating Synergy NS (multi-part TLDs)
     * @optional string $tld - e.g. .com.au
     * @optional bool $skip_ns - only create zone/records; do not call Synergy modifyNs
     * @optional bool $skip_mx
     * @optional bool $skip_mail
     * @optional string $dkim_txt
     * @optional string $ipv6
     *
     * @return array{zone: array, dns: array, nameservers: list<string>, synergy_ns_updated: bool}
     */
    #[RequiredParams([
        'domain' => 'Domain name is missing',
        'ip' => 'Server IPv4 is missing',
    ])]
    public function cloudflare_migrate_from_synergy($data)
    {
        $this->checkPermissions('servicedomain', 'manage_domains');

        $domainName = strtolower(trim((string) $data['domain']));
        $cf = $this->_getCloudflareDnsAdapter();
        $options = [];
        if (!empty($data['dkim_txt'])) {
            $options['dkim_txt'] = (string) $data['dkim_txt'];
        }
        if (!empty($data['ipv6'])) {
            $options['ipv6'] = (string) $data['ipv6'];
        }
        if (!empty($data['skip_mx'])) {
            $options['skip_mx'] = true;
        }
        if (!empty($data['skip_mail'])) {
            $options['skip_mail'] = true;
        }

        $dns = $cf->applyHostingDns($domainName, (string) $data['ip'], $options);
        $zone = $dns['zone'];
        $ns = $zone['name_servers'];
        $nsUpdated = false;

        if (empty($data['skip_ns']) && count($ns) >= 2) {
            $sld = isset($data['sld']) ? (string) $data['sld'] : '';
            $tld = isset($data['tld']) ? (string) $data['tld'] : '';
            if ($sld === '' || $tld === '') {
                throw new \FOSSBilling\Exception(
                    'sld and tld are required to update Synergy nameservers (e.g. sld=example tld=.com.au)'
                );
            }
            $synergy = $this->_getSynergyDnsAdapter(['registrar' => 'Synergy']);
            $regDomain = new \Registrar_Domain();
            $regDomain->setSld($sld);
            $regDomain->setTld($tld);
            $regDomain->setNs1($ns[0]);
            $regDomain->setNs2($ns[1]);
            if (isset($ns[2])) {
                $regDomain->setNs3($ns[2]);
            }
            if (isset($ns[3])) {
                $regDomain->setNs4($ns[3]);
            }
            $synergy->modifyNs($regDomain);
            $nsUpdated = true;
        }

        try {
            $cf->setSslMode($domainName, 'full');
        } catch (\Throwable) {
        }

        return [
            'zone' => $zone,
            'dns' => $dns,
            'nameservers' => $ns,
            'synergy_ns_updated' => $nsUpdated,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function _getSynergyDnsAdapter(array $data): \Registrar_Adapter_Synergy
    {
        $adapterName = (string) ($data['registrar'] ?? 'Synergy');
        $registrar = $this->getService()->registrarFindByAdapter($adapterName);
        if (!$registrar) {
            throw new \FOSSBilling\Exception('Registrar adapter :adapter is not installed', [':adapter' => $adapterName]);
        }
        $adapter = $this->getService()->registrarGetRegistrarAdapter($registrar);
        if (!$adapter instanceof \Registrar_Adapter_Synergy) {
            throw new \FOSSBilling\Exception('Registrar :adapter does not support Synergy DNS zone methods', [':adapter' => $adapterName]);
        }

        return $adapter;
    }

    private function _getCloudflareDnsAdapter(): \Dns_Adapter_Cloudflare
    {
        $cf = \Dns_Adapter_Cloudflare::fromFossConfig();
        if (!$cf instanceof \Dns_Adapter_Cloudflare || !$cf->isEnabled()) {
            // Allow admin ops when token present even if enabled=false — prefer explicit construct from config.
            $cfg = \FOSSBilling\Config::getProperty('cloudflare_dns', []);
            if (!is_array($cfg) || trim((string) ($cfg['api_token'] ?? '')) === '') {
                throw new \FOSSBilling\Exception(
                    'Cloudflare DNS is not configured. Set cloudflare_dns.api_token and account_id in config.php'
                );
            }
            $cf = new \Dns_Adapter_Cloudflare(array_merge($cfg, ['enabled' => true]));
        }
        $cf->setLog($this->getDi()['logger']);

        return $cf;
    }

    #[RequiredParams(['order_id' => 'Order ID is missing'])]
    protected function _getService($data)
    {
        $orderId = $data['order_id'];

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');

        $orderService = $this->getDi()['mod_service']('order');
        $s = $orderService->getOrderService($order);

        if (!$s instanceof ServiceDomain) {
            throw new \FOSSBilling\Exception('Domain order is not activated');
        }

        return $s;
    }

    private function _getRegistrar(int $id): TldRegistrar
    {
        $model = $this->getDi()['em']->getRepository(TldRegistrar::class)->find($id);
        if (!$model instanceof TldRegistrar) {
            throw new \FOSSBilling\Exception('Registrar not found');
        }

        return $model;
    }
}
