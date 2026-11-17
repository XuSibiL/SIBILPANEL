# SIBILPANEL 3.18.14

Aligned with provider docs/15-address-map.md:
- Admin storefront address is /g/<slug>; API address is /api/v1 and is labeled server-only. API key label is explicit.
- Same six settings plus Back. Storefront input preserves the public URL separately and derives the API origin internally; legacy /api/v1 input remains compatible. API keys are never displayed.
- Existing API-only installations discover storefront from authenticated GET /store slug during normal webhook checks. Cache is scoped to API endpoint and key; no hardcoded merchant slug. Unknown storefront is shown as unknown, never as an API URL.
- The internal legacy endpoint setting remains an API base to preserve saved financial profiles. Existing invoices and financial records are not rewritten.
- Reject invoice paths /pay/... and unknown paths as configuration inputs. Preserve HTTPS and explicit port.
- Customer payment links remain the authoritative invoice payment_url. A generic storefront is not substituted for an independent invoice.
- Existing webhook registration, conflict preservation, API payment verification, cashback, KYC and late-review logic are retained. A fresh registration status check is scheduled after upgrade; matching webhook is not rewritten.

Publish then update installed bots normally. Registration checks require the scheduler; an existing API-only installation may show storefront unknown until GET /store supplies slug. No live payment E2E certification. Roll back runtime only with the current financial DB; never restore stale balances.
