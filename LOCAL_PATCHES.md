# Local patches (vs upstream FOSSBilling)

Track divergences from [FOSSBilling/FOSSBilling](https://github.com/FOSSBilling/FOSSBilling) that should be reviewed on each upstream merge. When upstream lands an equivalent fix, remove the local change and mark the entry resolved.

## Active

### stripe-validatePaymentAmount-float

| | |
| --- | --- |
| **Status** | Active (local) |
| **Upstream** | Still broken on `upstream/main` as of 2026-08-10 — `validatePaymentAmount($tx->getAmount(), …)` with no cast |
| **Symptom** | Stripe payment succeeds; FOSSBilling transaction status `error`; invoice stays unpaid |
| **Error** | `0 - Box\Mod\Invoice\Service::validatePaymentAmount(): Argument #1 ($received) must be of type float, string given, called in …/library/Payment/Adapter/Stripe.php on line …` |
| **Cause** | `Transaction::getAmount()` returns `?string`. `validatePaymentAmount(float $received, …)` requires `float`. `Stripe.php` has `declare(strict_types=1)`, so PHP throws `TypeError` instead of coercing. Caught by `ServiceTransaction` as error code `0`. PayPal adapter already casts: `(float) $ipn['mc_gross']`. |
| **Fix** | Cast at both Stripe call sites: `(float) $tx->getAmount()` |
| **Files** | `src/library/Payment/Adapter/Stripe.php` (redirect/`processPaymentIntent` path and `applyOneTimePayment` webhook path) |
| **How to drop** | After merging upstream, if both call sites already cast to `float` (or `getAmount()` returns `float`), remove the `LOCAL PATCH` comments and this entry. |

### invoice-api-doctrine-load

| | |
| --- | --- |
| **Status** | Active (local) |
| **Upstream** | Incomplete after Invoice RedBean→Doctrine (#4122): `Invoice\Api\{Admin,Client,Guest}` still load via `db->getExistingModelById('Invoice')` / `findOne('Invoice')` while `Model_Invoice` was deleted and Service methods type-hint `Entity\Invoice` |
| **Symptom** | Invoice admin/client/guest APIs fail once Model_Invoice is gone (autoload / type errors) |
| **Fix** | Load via `em->getRepository(Invoice::class)` / `findByHash`; use Doctrine getters (`getId()`, `getHash()`) in Client renewal/funds flows |
| **Files** | `src/modules/Invoice/Api/Admin.php`, `Client.php`, `Guest.php` |
| **How to drop** | When upstream APIs load Doctrine `Invoice` entities end-to-end, remove this entry |

### stripe-vioflare-branding-and-spa

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | Keep Vioflare invoice title branding; keep headless SPA helpers (`createInvoicePaymentIntent`, reconcile helpers, `_oneTimeIntentParams`) adapted to Doctrine `Invoice`/`Transaction` |
| **Files** | `src/library/Payment/Adapter/Stripe.php`, `src/modules/Invoice/Service.php` SPA methods, `src/modules/Invoice/Api/Admin.php` stripe_* endpoints |

### vioflare-email-invoice-branding

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | Customer email Twig shells use Vioflare crimson `#d7265c`, `https://vioflare.com/logo.png`, legal footer links; PDF uses `custom-invoice.twig`/`css`; PNG at `public/branding/logo.png`. See `vioflare/fossbilling-docs/EMAIL_BRANDING.md`. After deploy, reset overridden `email_template` rows and set company logo to the PNG. |
| **Files** | `src/modules/*/templates/email/mod_*.html.twig` (customer-facing), `src/modules/Invoice/templates/pdf/custom-invoice.*`, `src/modules/Invoice/templates/client/mod_invoice_print.html.twig`, `src/public/branding/logo.png` |

### cloudflare-dns-provider

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | Synergy stays registrar; Cloudflare DNS adapter for zones/records/proxy. Domain activate → CF zone + Synergy NS; hosting activate → CF `applyHostingDns` (orange web, grey mail). Config key `cloudflare_dns`. Docs: `fossbilling-docs/cloudflare-dns.md` |
| **Files** | `src/library/Dns/Adapter/Cloudflare.php`, `Servicedomain/Service.php`, `Servicedomain/Api/Admin.php`, `Servicehosting/Service.php`, `config-sample.php` |

## Resolved

_(none yet)_
