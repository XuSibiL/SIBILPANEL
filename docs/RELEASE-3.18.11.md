# 3.18.11 — سیبیل پی (کارت به کارت آنی)

Base: 998d08d (SIBILPANEL 3.18.10). Additive gateway; existing wallets, panel lifecycle and other payment providers remain intact.

## Administration

Exactly six controls, plus Back: on/off, gateway address, API key, minimum deposit (toman), maximum deposit (toman), cashback percent (0–100). No second secret, daily-limit, webhook editor or connection-test buttons. Saving the key does not automatically enable the gateway. Default is OFF, cashback 0, minimum 20,000 and maximum 1,000,000 toman.

The embedded tutorial says: start @sibilpay, request a store, and after approval open «درگاه‌ها و API», obtain the gateway address/API key and enter them here. Credentials are never echoed. Existing owner/co-owner finance access rules protect the screen; wizard completion rechecks authority.

## Financial behavior

- Live API only, bearer authorization. Preserve the configured HTTPS host and port; normalize `/g/<slug>` and `/api/v1` without duplicating the API suffix. API redirects are NOT followed and get a specific configuration message.
- Each independent local order gets its own cryptographically random remote `order_id`. Equal-amount wallet orders remain independent. Re-clicking the SAME local order reuses only that order's pending invoice.
- Create expiry is 30 minutes. Local existing order TTL rules and late-payment finance escalation remain unchanged.
- Show exact `payable_rial` converted to decimal toman without rounding. Nominal wallet credit remains the local order amount, not the unique-rial suffix.
- Persist creation before the remote request. Timeouts/5xx/malformed responses remain recoverable rather than being treated as definite creation failures. Recovery uses `GET /invoices/by-order/{order_id}`. Only structured `404 INVOICE_NOT_FOUND` proves absence; generic 404/auth/redirect failures do not.
- A definite structured create 4xx rejects only that attempt and allows another attempt. No record is deleted. No unknown attempt blocks another order for the same user.
- Store each invoice's immutable connection in private database tables; changing the configured site/key does not retarget existing invoices. Credential history has the same database trust boundary as settings; keep database backups private. A revoked historical key can prevent historical verification until access is restored; the integration never guesses a different merchant identity.
- Validate returned invoice/order identity, live mode, exact nominal amount and unique payable amount. Pending/partial amounts do NOT credit the wallet. A paid invoice must pass authenticated API retrieval plus `/verify`, with authoritative status fallback for `INVOICE_ALREADY_VERIFIED`.
- Use the existing atomic settlement, top-up delivery, configured-percent cashback, financial reporting and wallet-recovery mechanisms. Duplicate processing cannot grant the same payment/cashback twice. Existing late payments remain finance-reviewed, not force-credited.
- SibilPay is a rial/KYC gateway; TetraPay remains exempt as before.

## Automatic reconciliation and optional webhook

No extra administrator credential is required. A bounded isolated `--sibilpay-worker` process handles due requests; bot-loop/shutdown wakes and the normal `--pay-poll` sweep are durable fallbacks. Typical due checks are scheduled after 30 seconds; transport failures back off for 60 seconds. This is not a latency guarantee.

Optional `/pay/sibilpay/webhook` POST and `/pay/sibilpay?order=...` browser return only queue an untrusted hint for a known random order identifier. Neither can directly settle from posted fields. Hints are rate-limited per invoice and all credit still requires the authenticated provider API and verification. This design intentionally avoids assuming either of the conflicting HMAC examples in provider docs 04 and 14; no unsigned request is treated as proof of payment. No store-level webhook URL is automatically overwritten. Polling works without registering a webhook; merchants may optionally register the existing public endpoint through the provider.

`200` from the optional hint endpoint means accepted for reconciliation, NOT proof of payment. Webhook events claiming `paid`, altered browser query parameters and fake receipts cannot grant credit.

## Compatibility / rollout

Provider references: public XuSibiL/Sibilpayy docs 03, 04, 08, 10–14, reviewed at build time. API and payment links must actually work on the configured origin/port; `/health` alone does not prove the API route is correct. No redirect, card creation, manual deposit confirmation or payment simulation is performed at installation.

New database tables are additive, created idempotently. No existing financial/user/panel row is deleted or rewritten by migration. No separate daemon/service or scheduler configuration is installed. Existing public callback serving remains as configured for the panel.

Rollback: retain a copy of the previously deployed `sibil.php`, disable new SibilPay checkouts, allow existing payments to be reconciled, and restore only code if required. Do not restore an older database over newer financial transactions; keep the additive tables/history. In-flight SibilPay invoices require the new reconciler or explicit financial handling after code rollback.

## Validation

Dedicated mocked regression covers defaults, six-control UI, permission checks, site/key normalization, exact rial amounts, independent topups, same-order idempotency, lost-response recovery, generic/auth 404 safety, structured rejection, redirect messaging, historical connection lookup, underpayments, forged webhook hints, mismatched order/amount/test-mode/host/verify responses, already-verified recovery, cashback and exactly-once delivery. Full pre-existing regression matrix also run locally. No live merchant/provider end-to-end payment certification is claimed.
