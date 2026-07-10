<?php

declare(strict_types=1);

namespace Box\Mod\StripeVault;

use FOSSBilling\InformationException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Stripe Vault service — SetupIntent flow and off-session payment charging.
 *
 * Credentials are loaded from the FOSSBilling Stripe payment gateway row so
 * there is a single source of truth.  Test-mode is respected automatically.
 *
 * Card data never passes through this server; only Stripe payment-method IDs
 * (pm_xxx) are handled here.
 */
class Service implements \FOSSBilling\InjectionAwareInterface
{
    private ?\Pimple\Container $di = null;

    private ?StripeClient $stripeClient = null;

    private ?string $publishableKey = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    // ── Stripe client ─────────────────────────────────────────────────────────

    /**
     * Lazily initialise the Stripe client from the FOSSBilling PayGateway config.
     *
     * @throws InformationException if the Stripe gateway is not configured
     */
    private function stripe(): StripeClient
    {
        if ($this->stripeClient !== null) {
            return $this->stripeClient;
        }

        $gateway = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Stripe']);
        if (!$gateway) {
            throw new InformationException('The Stripe payment gateway is not configured in FOSSBilling.');
        }

        $config = json_decode($gateway->config ?? '{}', true) ?? [];
        $testMode = (bool) ($gateway->test_mode ?? false);

        $secretKey = $testMode
            ? ($config['test_api_key'] ?? null)
            : ($config['api_key'] ?? null);

        $this->publishableKey = $testMode
            ? ($config['test_pub_key'] ?? null)
            : ($config['pub_key'] ?? null);

        if (empty($secretKey)) {
            throw new InformationException('Stripe secret key is not configured. Set it in the Stripe payment gateway settings.');
        }

        $this->stripeClient = new StripeClient($secretKey);

        return $this->stripeClient;
    }

    public function getPublishableKey(): string
    {
        $this->stripe(); // ensure loaded

        if (empty($this->publishableKey)) {
            throw new InformationException('Stripe publishable key is not configured.');
        }

        return $this->publishableKey;
    }

    // ── Stripe Customer ───────────────────────────────────────────────────────

    /**
     * Find or create a Stripe Customer for the given FOSSBilling client.
     *
     * The Stripe Customer ID is cached in the client's custom field
     * `stripe_customer_id` to avoid duplicate lookups.
     */
    public function getOrCreateStripeCustomer(\Model_Client $client): string
    {
        $stripe = $this->stripe();

        // Check for a cached Customer ID in client custom fields
        $customField = $this->di['db']->findOne(
            'ClientCustomField',
            'client_id = ? AND name = ?',
            [$client->id, 'stripe_customer_id'],
        );

        if ($customField && !empty($customField->value)) {
            // Verify the Customer still exists in Stripe
            try {
                $customer = $stripe->customers->retrieve($customField->value);
                if (!isset($customer->deleted) || !$customer->deleted) {
                    return $customField->value;
                }
            } catch (ApiErrorException) {
                // Customer no longer exists — fall through to create a new one
            }
        }

        // Look up by email first
        $existing = $stripe->customers->all(['email' => $client->email, 'limit' => 1]);
        if (count($existing->data) > 0) {
            $customerId = $existing->data[0]->id;
        } else {
            $name = trim($client->first_name . ' ' . $client->last_name);
            $customer = $stripe->customers->create([
                'email' => $client->email,
                'name' => $name ?: null,
                'metadata' => ['fossbilling_client_id' => (string) $client->id],
            ]);
            $customerId = $customer->id;
        }

        // Cache the Customer ID
        if ($customField) {
            $customField->value = $customerId;
            $customField->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($customField);
        } else {
            $cf = $this->di['db']->dispense('ClientCustomField');
            $cf->client_id = $client->id;
            $cf->name = 'stripe_customer_id';
            $cf->value = $customerId;
            $cf->created_at = date('Y-m-d H:i:s');
            $cf->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($cf);
        }

        return $customerId;
    }

    // ── SetupIntent ───────────────────────────────────────────────────────────

    /**
     * Create a Stripe SetupIntent for saving a card off-session.
     *
     * Returns publishable_key + client_secret so the Vue frontend can confirm
     * the SetupIntent via Stripe.js without card data touching this server.
     *
     * @return array{publishable_key: string, client_secret: string}
     */
    public function createSetupIntent(\Model_Client $client): array
    {
        $stripe = $this->stripe();
        $customerId = $this->getOrCreateStripeCustomer($client);

        try {
            $setupIntent = $stripe->setupIntents->create([
                'customer' => $customerId,
                'usage' => 'off_session',
                'automatic_payment_methods' => ['enabled' => true],
            ]);
        } catch (ApiErrorException $e) {
            throw new InformationException('Stripe error creating SetupIntent: :msg', [':msg' => $e->getMessage()]);
        }

        return [
            'publishable_key' => $this->getPublishableKey(),
            'client_secret' => $setupIntent->client_secret,
        ];
    }

    // ── Payment Methods ───────────────────────────────────────────────────────

    /**
     * List saved card payment methods for a client.
     * Deduplicates by card fingerprint, keeping the most recently added card.
     *
     * @return list<array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, fingerprint: string|null}>
     */
    public function listPaymentMethods(\Model_Client $client): array
    {
        $stripe = $this->stripe();
        $customerId = $this->getOrCreateStripeCustomer($client);

        try {
            $pms = $stripe->customers->allPaymentMethods($customerId, ['type' => 'card']);
        } catch (ApiErrorException $e) {
            throw new InformationException('Stripe error listing payment methods: :msg', [':msg' => $e->getMessage()]);
        }

        $seenFingerprints = [];
        $out = [];

        foreach ($pms->data as $pm) {
            $card = $pm->card ?? null;
            $fingerprint = $card?->fingerprint ?? null;

            if ($fingerprint !== null && isset($seenFingerprints[$fingerprint])) {
                continue;
            }

            if ($fingerprint !== null) {
                $seenFingerprints[$fingerprint] = true;
            }

            $out[] = [
                'id' => $pm->id,
                'brand' => $card?->brand ?? 'unknown',
                'last4' => $card?->last4 ?? '****',
                'exp_month' => (int) ($card?->exp_month ?? 0),
                'exp_year' => (int) ($card?->exp_year ?? 0),
                'fingerprint' => $fingerprint,
            ];
        }

        return $out;
    }

    /**
     * Detach a payment method from the Stripe Customer (removes it from the vault).
     *
     * @throws InformationException if the PM does not belong to this client
     */
    public function detachPaymentMethod(\Model_Client $client, string $pmId): bool
    {
        $stripe = $this->stripe();
        $customerId = $this->getOrCreateStripeCustomer($client);

        // Verify the PM belongs to this customer before detaching
        try {
            $pm = $stripe->paymentMethods->retrieve($pmId);
        } catch (ApiErrorException $e) {
            throw new InformationException('Payment method not found: :msg', [':msg' => $e->getMessage()]);
        }

        if (($pm->customer ?? null) !== $customerId) {
            throw new InformationException('Payment method does not belong to your account.');
        }

        try {
            $stripe->paymentMethods->detach($pmId);
        } catch (ApiErrorException $e) {
            throw new InformationException('Stripe error detaching payment method: :msg', [':msg' => $e->getMessage()]);
        }

        return true;
    }

    // ── Pay Invoice ───────────────────────────────────────────────────────────

    /**
     * Charge a saved payment method for a specific invoice.
     *
     * Flow:
     *   1. Verify the invoice belongs to this client and is unpaid
     *   2. Create + confirm a PaymentIntent off-session
     *   3. On success, add funds to the client and mark the invoice paid
     *
     * @return array{transaction_id: string, status: string, invoice_id: int}
     *
     * @throws InformationException on invoice ownership, payment failure, or Stripe error
     */
    public function payInvoice(\Model_Client $client, int $invoiceId, string $pmId): array
    {
        /** @var \Model_Invoice|null $invoice */
        $invoice = $this->di['db']->findOne(
            'Invoice',
            'id = ? AND client_id = ?',
            [$invoiceId, $client->id],
        );

        if (!$invoice) {
            throw new InformationException('Invoice #:id not found in your account.', [':id' => $invoiceId]);
        }

        if ($invoice->status === \Model_Invoice::STATUS_PAID) {
            throw new InformationException('Invoice #:id is already paid.', [':id' => $invoiceId]);
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $totalWithTax = $invoiceService->getTotalWithTax($invoice);
        $amountCents = (int) round($totalWithTax * 100);
        $currency = strtolower((string) ($invoice->currency ?: 'aud'));

        $stripe = $this->stripe();
        $customerId = $this->getOrCreateStripeCustomer($client);

        // Verify the PM belongs to this customer
        try {
            $pm = $stripe->paymentMethods->retrieve($pmId);
        } catch (ApiErrorException $e) {
            throw new InformationException('Payment method not found: :msg', [':msg' => $e->getMessage()]);
        }

        if (($pm->customer ?? null) !== $customerId) {
            throw new InformationException('Payment method does not belong to your account.');
        }

        // Build a human-readable description
        $invoiceItems = $this->di['db']->getAll(
            'SELECT title FROM invoice_item WHERE invoice_id = :id LIMIT 3',
            [':id' => $invoice->id],
        );
        $description = empty($invoiceItems)
            ? "Invoice #{$invoice->id}"
            : implode(', ', array_column($invoiceItems, 'title'));

        // Create and confirm the PaymentIntent
        try {
            $intent = $stripe->paymentIntents->create(
                [
                    'amount' => $amountCents,
                    'currency' => $currency,
                    'customer' => $customerId,
                    'payment_method' => $pmId,
                    'description' => $description,
                    'confirm' => true,
                    'off_session' => true,
                    'receipt_email' => $client->email,
                    'metadata' => [
                        'client_id' => (string) $client->id,
                        'invoice_id' => (string) $invoice->id,
                    ],
                ],
                ['idempotency_key' => sprintf('vf_vault_invoice_%d_%d', $invoice->id, $amountCents)],
            );
        } catch (ApiErrorException $e) {
            throw new InformationException('Payment failed: :msg', [':msg' => $e->getMessage()]);
        }

        if ($intent->status !== 'succeeded') {
            throw new InformationException('Payment was not completed. Stripe status: :status', [':status' => $intent->status]);
        }

        // Mark invoice paid — same logic as Stripe adapter processTransaction()
        $clientService = $this->di['mod_service']('client');

        $transactionRecord = [
            'amount' => $totalWithTax,
            'description' => 'Stripe PaymentIntent ' . $intent->id,
            'type' => 'transaction',
            'rel_id' => null,
        ];

        $clientService->addFunds($client, $totalWithTax, $transactionRecord['description'], $transactionRecord);

        if (!$invoice->approved) {
            $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
        }
        $invoiceService->payInvoiceWithCredits($invoice);

        return [
            'transaction_id' => $intent->id,
            'status' => 'succeeded',
            'invoice_id' => (int) $invoice->id,
        ];
    }
}
