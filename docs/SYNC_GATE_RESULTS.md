# Preserve-all sync gate results — 2026-10-09

Branch: `sync/upstream-0.8.8` @ `ecea6fa2d`  
Pre-sync tag: `vioflare-pre-sync-20261009` → `989a915aa`  
Merged: `upstream/main` (Entity-compatible; not raw `tags/0.8.8` RedBean Stripe)

## Gate checklist

| Gate | Result |
|------|--------|
| Inventory paths present | PASS (Synergy, Cloudflare, OpenPanel, Stripe SPA, Invoice/Order/Servicedomain/Servicehosting, branding, deploy.yml, LOCAL_PATCHES) |
| Required symbols | PASS (`createInvoicePaymentIntent`, `finalizeAuthorizedPaymentIntent`, `captureAuthorizedPaymentIntent`, `stripe_capture_authorized`, `invoiceRequiresManualCapture`, `domainRegisterAU`, `auEligibilityPayload`, `setOrderConfig`, `unpaid_invoice_id`) |
| Diff review | PASS — Vioflare APIs retained; createOrder invoice path aligned to Doctrine `generateForOrder($order, null, false)` |
| Unit tests | PASS — `composer test` → **4312 tests, 17893 assertions, 4 skipped** (PostgreSQL-only), 0 failures |
| Model_ClientOrder shim | Restored as non-RedBean compatibility class for `getLegacyOrder` bridges |

## Push / deploy commands (run when remote push is approved)

```bash
cd /Users/zainmehdi/ZG/vioflare/billing.vioflare.com
git push -u origin sync/upstream-0.8.8
git checkout production
git merge --ff-only sync/upstream-0.8.8
git push origin production
gh workflow run deploy.yml --ref production
gh run watch  # wait for Deploy FOSSBilling success
```

Rollback: redeploy from `vioflare-pre-sync-20261009`.
