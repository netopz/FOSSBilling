# Sync inventory — preserve-all to tags/0.8.8

- **Pre-sync tag:** `vioflare-pre-sync-20261009` → `b18600895`
- **Target:** `tags/0.8.8` → `d3338292b`
- **Merge-base:** `544bf2b80`

## Vioflare-critical paths (must survive)

| Path | Role |
|------|------|
| `src/library/Registrar/Adapter/Synergy.php` | Synergy registrar + AU |
| `src/library/Dns/Adapter/Cloudflare.php` | Cloudflare DNS |
| `src/library/Server/Manager/OpenPanel.php` | OpenPanel hosting |
| `src/library/Payment/Adapter/Stripe.php` | SPA + manual capture |
| `src/modules/Invoice/Service.php` | Manual capture detect + reconcile |
| `src/modules/Invoice/Api/Admin.php` | `stripe_*` admin APIs |
| `src/modules/Order/Service.php` | `unpaid_invoice_id` update |
| `src/modules/Servicedomain/Service.php` | Activate NS/action + CF |
| `src/modules/Servicehosting/Service.php` | Synergy DNS + OpenPanel |
| `src/public/branding/logo.png` | Branding |
| `.github/workflows/deploy.yml` | Deploy |
| `LOCAL_PATCHES.md` | Patch tracker |

## Required symbols (gate)

- `createInvoicePaymentIntent`
- `finalizeAuthorizedPaymentIntent`
- `captureAuthorizedPaymentIntent`
- `stripe_capture_authorized`
- `invoiceRequiresManualCapture`
- `domainRegisterAU` / `auEligibilityPayload` / `setOrderConfig`
- Order update `unpaid_invoice_id`
- `Registrar_Adapter_Synergy`, `Dns_Adapter_Cloudflare`, OpenPanel manager class

## Local-only commits (sample; full log: `git log tags/0.8.8..production`)

- `b18600895` Authorize domain payments / AU registration
- `15a76c45b` Currency on client create
- `de45ae15b` ClientOrder invoice issue
- `e62dd5512` OpenPanel + Cloudflare harden
- `aac839b9e` Synergy checkDomain create
- `d40c30d26` Email templates
- `893db12ea` CF adapter
- Synergy DNS / OpenPanel AutoSSL / DKIM commits
- Deploy autoload fixes
