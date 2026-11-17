# 3.18.15 — key-scoped multi-webhook

Provider contract: Sibilpayy cd26cb6, docs18. Only API key + site are required.
- GET/PUT hooks/self; persist endpoint secret; rotate only our random-token URL after lost secret.
- No admin token, no destructive admin PUT, no PATCH store. Explicit webhook_endpoint per new invoice.
- Signed new inbox: timestamp HMAC, 300s window, durable delivery dedup and key/profile binding. Legacy wake-only callbacks retained for old invoices; authenticated GET + verify is ALWAYS required.
- <=10 rial suffix tolerance with integer amounts, paid API state, full principal, matching verify. Never force settlement or replay delivery.
- Empty fee-wallet remains a creation error; existing payments still poll/verify normally. Other gateways, cashback policy, KYC and late-review rules preserved.
- Additive SQLite tables only. Existing financial records and connection profiles retained.
- Roll back code only using the CURRENT database; do not restore stale financial DB. Signed callbacks require new receiver compatibility.
No production deployment or live provider E2E implied by local tests.
