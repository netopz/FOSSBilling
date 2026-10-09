# Local patches (vs upstream FOSSBilling)

Track divergences from [FOSSBilling/FOSSBilling](https://github.com/FOSSBilling/FOSSBilling) that should be reviewed on each upstream merge. When upstream lands an equivalent fix, remove the local change and mark the entry resolved.

## Active

### stripe-vioflare-branding-and-spa

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | Vioflare invoice title branding; headless SPA helpers (`createInvoicePaymentIntent`, reconcile, `_oneTimeIntentParams`); **manual capture** for domain invoices (`finalizeAuthorizedPaymentIntent`, `captureAuthorizedPaymentIntent`, `stripe_capture_authorized`, `invoiceRequiresManualCapture`). |
| **Files** | `src/library/Payment/Adapter/Stripe.php`, `src/modules/Invoice/Service.php`, `src/modules/Invoice/Api/Admin.php` |

### unpaid-invoice-id-order-update

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | `order/update` accepts `unpaid_invoice_id` so middleware combined checkout can link orders to one prepared invoice. |
| **Files** | `src/modules/Order/Service.php` |

### synergy-registrar-au

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | Full Synergy Wholesale adapter; `.au` uses `domainRegisterAU` + eligibility from order config; `setOrderConfig` from Servicedomain activate. |
| **Files** | `src/library/Registrar/Adapter/Synergy.php`, `src/modules/Servicedomain/Service.php` |

### vioflare-email-invoice-branding

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | Customer email Twig shells use Vioflare crimson `#d7265c`, `https://vioflare.com/logo.png`, legal footer links; PDF uses `custom-invoice.twig`/`css`; PNG at `public/branding/logo.png`. |
| **Files** | `src/modules/*/templates/email/mod_*.html.twig`, `src/modules/Invoice/templates/pdf/custom-invoice.*`, `src/public/branding/logo.png` |

### cloudflare-dns-provider

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | Synergy stays registrar; Cloudflare DNS adapter for zones/records/proxy. |
| **Files** | `src/library/Dns/Adapter/Cloudflare.php`, Servicedomain/Servicehosting services, `config-sample.php` |

### openpanel-server-manager

| | |
| --- | --- |
| **Status** | Active (intentional product fork) |
| **Notes** | OpenPanel server manager + hosting activate DNS/DKIM/AutoSSL hooks. |
| **Files** | `src/library/Server/Manager/OpenPanel.php`, `src/modules/Servicehosting/Service.php` |

## Resolved

### stripe-validatePaymentAmount-float

| | |
| --- | --- |
| **Status** | Resolved (upstream/main cast present after 2026-10 sync) |
| **Notes** | Both Stripe call sites use `(float) $tx->getAmount()`. |

### invoice-api-doctrine-load

| | |
| --- | --- |
| **Status** | Resolved (upstream/main uses Invoice repositories end-to-end) |
| **Notes** | Admin/Client/Guest load via `getInvoiceRepository()` / `findByHash`. |
