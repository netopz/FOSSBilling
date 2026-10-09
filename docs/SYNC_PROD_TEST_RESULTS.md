# Production money-path tests — post sync deploy

Deploy: https://github.com/netopz/FOSSBilling/actions/runs/37942712822 (`7d205651a`)  
Follow-up: admin finalization fix (perms) + deploy harden `0aed9466e`  
Pre-sync rollback tag: `vioflare-pre-sync-20261009` @ `989a915aa`  
Gate: `docs/SYNC_GATE_RESULTS.md` (4312 unit tests green)

## P0 — Payment + domain

| # | Test | Result | Evidence |
|---|------|--------|----------|
| 1 | Domain-only checkout → PI `capture_method=manual` → authorize → Synergy OK → capture + Paid + Active | **DEFERRED (manual)** | Code live on server (Stripe SPA + manual capture symbols present). Full card checkout not automated this run — needs staff SPA test with test/live card. |
| 2 | Authorize hold → admin Activate → `stripe_capture_authorized` → Paid | **DEFERRED (manual)** | Prefer staging / known-bad eligibility; admin API `stripe_capture_authorized` present in deployed Invoice Admin API. |
| 3 | Portal domain Active shows Renew | **PASS (smoke)** | Portal `/portal/domains` HTTP 200; Active domain `hoppersroadworthy.com.au` still active post-sync (order Active, NS intact). UI Renew vs Complete registration requires logged-in browser confirm. |
| 4 | Portal `failed_setup` → Complete registration | **DEFERRED** | No safe failed_setup test order created this window. |
| 5 | Hosting + domain combined checkout | **DEFERRED (manual)** | Same as #1 — needs SPA checkout. |
| 6 | Portal invoice pay automatic capture | **DEFERRED (manual)** | Hosting renewal path; automatic capture code path unchanged and present. |

## P1 — Registrar / DNS / panel

| # | Test | Result | Evidence |
|---|------|--------|----------|
| 7 | Synergy checkDomain free name → available | **PASS** | Public `GET /api/v1/domains/check` → available; server `Registrar_Adapter_Synergy::isDomainAvailable('availtest1791563774.com')` → true |
| 8 | Active domain NS ns1/ns2.vioflare.com | **PASS** | Domain id 28 `hoppersroadworthy.com.au` — ns1/ns2.vioflare.com |
| 9 | Hosting activate / OpenPanel | **PASS** | Hosting id 63 / order 100 active; OpenPanel manage `https://pluto.vioflare.com:2083/` → 302 from billing host |

## P2 — Regression smoke

| # | Test | Result | Evidence |
|---|------|--------|----------|
| 10 | Admin login FOSS | **PASS** | After finalization fix: `/admin` → 302 → `/admin/staff/login` **200** |
| 11 | Client portal domains + invoices | **PASS** | `https://vioflare.com/portal/domains` + `/portal/invoices` → **200** |
| 12 | No PHP fatals during test window | **PASS** | 0 Fatal/Uncaught Stripe/Order/Synergy since deploy; cron header noise only |

## Incidents during test window

1. **Admin 500 on first hit after deploy** — UpdateFinalization could not write `themes/default/...` (ownership). Fixed forward: chown themes/data to www-data, remove leftover `install/`, `system:run-patcher`. No rollback.
2. **Deploy harden** — `0aed9466e` ensures SCRIPT_AFTER makes themes writable before patcher (next deploy).

## Rollback

Not triggered. Tag `vioflare-pre-sync-20261009` remains.

## Manual P0 follow-up (staff)

1. Domain-only cart checkout on production with a disposable name → confirm PI `requires_capture` then Paid/Active after Synergy.
2. Optional staging authorize-hold failure path.
3. Logged-in portal: Active domain shows **Renew**; any failed_setup shows **Complete registration**.
