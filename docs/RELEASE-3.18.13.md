# SIBILPANEL 3.18.13

- Align registration with the updated GET/PATCH /api/v1/store contract: explicit webhook_url null is empty; missing/malformed field is an incompatible provider response, never permission to overwrite.
- Run registration from the existing scheduled payments_poll path as well as the detached SibilPay worker. This closes the fallback gap when detached shell execution fails. Registration may take up to the normal scheduler interval; no new daemon or settings required.
- Revisioned webhook fingerprint schedules a fresh check after upgrade instead of carrying stale status forward.
- Separate provider contract errors from connection/auth errors; bounded HTTP status and sanitized error identifiers only. No API keys, response bodies or webhook secrets in status.
- Preserve a nonempty foreign webhook; PATCH only webhook_url; confirm via GET before reporting registered.
- Incoming events remain untrusted wake hints. Authoritative original-profile API verification remains mandatory for any financial effect. HMAC configuration is NOT introduced; provider signature documentation remains inconsistent. This release does not claim signature verification or use callback data as payment proof.
- Existing settings, cashback, KYC exceptions, other gateways, invoice identities and late-payment review remain unchanged. No financial data migrations.

## Deployment / verification
Publish then update installed bots normally. Keep the existing scheduled worker enabled. A public HTTPS callback must be reachable. Check gateway status then make a controlled payment and inspect provider /hooks or GET /api/v1/webhooks. No live end-to-end payment certification has been performed.

## Rollback
Use the previous runtime with the current database; never restore an older financial database over newer payments. A registered provider webhook is not automatically removed on rollback.
