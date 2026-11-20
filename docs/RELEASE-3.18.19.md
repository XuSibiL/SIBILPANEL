# 3.18.19 — SibilPay docs29/30 compatibility

- Accept both authenticated `ok:true` and `success:true` API envelopes; conflicting rejection stays rejected.
- Send per-invoice webhook_url for the locally registered endpoint, separate from the browser callback.
- Principal-only paid/manual-confirmed invoices settle only after matching API identity, live mode and verify.
- Keep strict modern HMAC, per-key/per-domain routing, native fulfillment, idempotence, shared KYC accounting and all other gateways unchanged.
- No live invoice creation or provider-server changes are claimed by local tests.
