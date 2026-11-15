# 3.18.12 — SibilPay webhook registration and priority reconciliation

Base: ae579c45d21f182f66b958bcbcf3fbf64a8f5f20 (3.18.11).

## Scope

Only SibilPay integration changes. Other gateways, KYC policy, balances, configured-percent cashback, wallet/panel lifecycle, order fulfillment and late-payment financial review retain their existing behavior. The same six SibilPay settings plus Back remain; webhook status is additional text, not another configuration button.

## Automatic setup

When SibilPay is enabled and its site/API key are configured, the isolated worker derives the public callback from the panel's existing HTTPS web address:

`https://<panel-host>[:port]/pay/sibilpay/webhook`

It first checks the local endpoint's nonfinancial GET health marker, without credentials or redirects. It then uses the documented provider API:

1. `GET /api/v1/store` with the configured live API key.
2. If the explicit `webhook_url` field is empty/null, `PATCH /api/v1/store` with **only** `webhook_url`.
3. Read back `GET /store`; report registration success only if the URL matches.
4. If the URL already matches, do not rewrite it.
5. If a different nonempty URL exists, preserve it and show a conflict. This avoids knowingly breaking Mirza/another integration. A separate provider store or a provider-supported multi-destination solution is needed for independent destinations.

Failed GET, absent/unrecognized fields, missing public HTTPS URL, unreachable endpoint or disabled gateway never cause a blind PATCH. Retrying follows bounded backoff. Same-panel registration workers are serialized; the provider API does not document conditional compare-and-swap, so simultaneous changes from external clients cannot be made atomic by this connector. No existing webhook is automatically removed on disable or rollback.

Do not enter the payment provider's domain as the panel callback: the callback must point to the server running SIBILPANEL's existing public payment endpoint. HTTPS/callback serving must be working. Registration is asynchronous after the bot runs; it is not performed by the GitHub publisher.

## Fast manual/automatic paid notifications

Provider POST events (including `invoice.paid` after a manual confirmation) persist a priority wake for the known random `order_id` and quickly return 200. No API request or credit occurs in the HTTP response path. A dedicated `--sibilpay-hook <payment-id>` process verifies that invoice immediately instead of waiting behind the ordinary poll backlog or its 30/60-second due time.

The worker retrieves the original invoice through the saved original connection, checks exact invoice/order/amount/live-mode fields and actual paid amount, calls `/verify`, and only then invokes the existing atomic settlement and cashback functions. The existing already-verified fallback remains authoritative. Duplicate callbacks cannot grant extra credit. API/network/verification failures keep durable retry work; the original polling path remains a fallback if spawning is unavailable or a response/event is lost.

Priority wakes have per-invoice rate limiting, process locks, generation-safe updates and bounded exponential retries. A false/unsigned payload is **only an untrusted wake**; neither `status=paid`, an amount nor a purported signature from the request is accepted as proof of payment. This preserves the API-verification security model of 3.18.11 and avoids guessing the mutually inconsistent HMAC formulas in provider docs 04 and 14. No extra webhook secret is requested from the admin. To add signature-only settlement in future, a clear provider signing contract and secure secret provisioning would be required; this release does NOT do that.

A successful webhook HTTP response means queued/accepted, not financial settlement. Actual customer/finance messages follow verified settlement. No fixed end-to-end latency can be guaranteed: provider event delivery, network, server load, API and Telegram remain dependencies. If the provider store has no webhook delivery for a confirmation made before registration, normal authenticated polling remains the recovery path; this release does not invent or replay provider manual-confirm requests.

Expired/canceled local invoices still follow existing late-payment finance review. A provider-side manual confirmation does not silently bypass local financial policy or force-credit an expired order.

## Deployment and rollback

Publish then update installed bots using the normal panel updater. The new wake table is additive and idempotent. No existing payment/user/panel rows are removed by migration. No services, unrelated cron entries or provider settings are changed by the publisher.

Keep the previous runtime file for source rollback. Do NOT restore an old financial database over newer payments. The old 3.18.11 endpoint can still treat incoming callbacks as ordinary poll hints; registration at the provider is intentionally not deleted by code rollback. Keep additive state/history and reconcile in-flight transactions before removing this integration entirely.

## Local validation

Dedicated tests cover registration/readback/idempotency, disabled/missing/invalid/unreachable configurations, preserving a foreign webhook, quick nonfinancial ingress, durable priority processing, no credit from fake paid hints, API/identity failure retries, duplicate-event idempotency, independent invoices, 9% configured cashback, late-payment review and unchanged six-control UI. The full existing regression matrix and PHP-only publisher matrix are run in the sandbox. Live provider webhook delivery is not certified by these mocked tests.
