# Production money-path tests — post sync deploy

Fill after `deploy.yml` succeeds on `production`. Stop and rollback to `vioflare-pre-sync-20261009` on any P0 fail.

## P0 — Payment + domain

| # | Test | Result | Evidence (invoice/order/PI) |
|---|------|--------|------------------------------|
| 1 | Domain-only checkout → PI `capture_method=manual` → authorize → Synergy OK → capture + Paid + Active | PENDING | |
| 2 | Authorize hold (staging preferred) → admin Activate → `stripe_capture_authorized` → Paid | PENDING | |
| 3 | Portal domain Active shows Renew (not Complete registration) | PENDING | |
| 4 | Portal domain `failed_setup` → Complete registration; Activate without renewal invoice | PENDING | |
| 5 | Hosting + domain combined checkout → single invoice; both activate | PENDING | |
| 6 | Portal invoice pay (hosting renewal) → automatic capture | PENDING | |

## P1 — Registrar / DNS / panel

| # | Test | Result | Evidence |
|---|------|--------|----------|
| 7 | Synergy `checkDomain` free name → available | PENDING | |
| 8 | Active domain NS `ns1/ns2.vioflare.com` (or account NS) | PENDING | |
| 9 | Hosting activate reaches OpenPanel (order Active / SSO) | PENDING | |

## P2 — Regression smoke

| # | Test | Result | Evidence |
|---|------|--------|----------|
| 10 | Admin login FOSS | PENDING | |
| 11 | Client portal `/portal/domains` + `/portal/invoices` | PENDING | |
| 12 | No PHP fatals in `data/log/php_error.log` during window | PENDING | |

## Notes

- Gate passed locally before deploy (see `docs/SYNC_GATE_RESULTS.md`).
- Deploy blocked pending remote push approval in agent session.
