<?php

declare(strict_types=1);

namespace Box\Mod\StripeVault\Api;

use FOSSBilling\InformationException;

/**
 * Client-level Stripe Vault API endpoints.
 *
 * FOSSBilling endpoint → method mapping:
 *   client.stripevault.create_setup_intent   → create_setup_intent()
 *   client.stripevault.list_payment_methods  → list_payment_methods()
 *   client.stripevault.detach_payment_method → detach_payment_method()
 *   client.stripevault.pay_invoice           → pay_invoice()
 *
 * All endpoints require the client to be authenticated.
 * Card data never reaches this server — only Stripe payment-method IDs (pm_xxx).
 */
class Client extends \Api_Abstract
{
    /**
     * Create a Stripe SetupIntent so the frontend can save a card via Stripe.js.
     *
     * The frontend should:
     *   1. Call this endpoint to get `client_secret` + `publishable_key`
     *   2. Use `stripe.confirmSetup({ elements, confirmParams })` to confirm
     *   3. Stripe returns a `pm_xxx` — POST it to the middleware to store/display
     *
     * @return array{publishable_key: string, client_secret: string}
     */
    public function create_setup_intent(array $data): array
    {
        $client = $this->getIdentity();

        return $this->getService()->createSetupIntent($client);
    }

    /**
     * List saved card payment methods for the authenticated client.
     * Deduplicated by card fingerprint.
     *
     * @return list<array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, fingerprint: string|null}>
     */
    public function list_payment_methods(array $data): array
    {
        $client = $this->getIdentity();

        return $this->getService()->listPaymentMethods($client);
    }

    /**
     * Remove a saved card from the Stripe vault.
     *
     * @param array{pm_id: string} $data
     */
    public function detach_payment_method(array $data): bool
    {
        $pmId = $this->_requireParam($data, 'pm_id');

        if (!str_starts_with($pmId, 'pm_')) {
            throw new InformationException('Invalid payment method ID format.');
        }

        $client = $this->getIdentity();

        return $this->getService()->detachPaymentMethod($client, $pmId);
    }

    /**
     * Pay an invoice using a saved Stripe payment method.
     *
     * The PM must belong to the authenticated client's Stripe Customer.
     * On success, the invoice is marked paid immediately without waiting for a webhook.
     *
     * @param array{invoice_id: int|string, pm_id: string} $data
     *   - invoice_id: FOSSBilling invoice ID
     *   - pm_id: Stripe payment method ID (pm_xxx)
     *
     * @return array{transaction_id: string, status: string, invoice_id: int}
     */
    public function pay_invoice(array $data): array
    {
        $invoiceId = (int) $this->_requireParam($data, 'invoice_id');
        $pmId = $this->_requireParam($data, 'pm_id');

        if ($invoiceId <= 0) {
            throw new InformationException('A valid invoice_id is required.');
        }

        if (!str_starts_with($pmId, 'pm_')) {
            throw new InformationException('Invalid payment method ID format.');
        }

        $client = $this->getIdentity();

        return $this->getService()->payInvoice($client, $invoiceId, $pmId);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

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
