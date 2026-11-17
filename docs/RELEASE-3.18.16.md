# 3.18.16 — concise SibilPay settings and callback self-probe fix

- Short Persian admin screen; show only storefront URL, key presence, limits, cashback and webhook status. Keep internal API base and saved credentials unchanged.
- Registration no longer requires merchant host to GET its own public callback. Hairpin DNS/firewall/proxy failures are not proof the provider cannot deliver. Endpoint is still confirmed by authenticated hooks/self and test delivery requested.
- A transient metadata error preserves the last confirmed explicit endpoint for that key/deployment; disabled/missing/unconfirmed endpoints fail closed. Never omit webhook_endpoint and route to another app by accident.
- Generic fee-wallet customer error. Stored internal reason and definite rejection behavior retained.
- No change to financial amount checks, verify, idempotence, KYC, cashback, other gateways or DB history.
- Provider-side invoice TypeError cannot be diagnosed/repaired from these docs. Provider traceback required; no automatic replay of uncertain invoice creations.
- Local tests only, not a live payment certification. Roll back code using CURRENT financial DB, never stale balances.
