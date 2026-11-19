# 3.18.18 — SibilPay current contract compatibility

Audited provider docs commit1704c792, docs19–28. Creation fees are charged when creating invoices: no automatic POST retries.
- Canonical payment_url or documented pay_url/short_payment_url aliases; exact original HTTPS origin checks retained.
- Compact creation responses hydrate with one GET by-order; no assumed live/test flag, no made-up expiry or order ID. Inconsistent/missing authoritative fields fail closed and remain recoverable.
- Signed flat or nested webhook bodies queue wake-ups only; modern endpoint HMAC remains mandatory. Provider docs21 explicitly supports modern-only receivers through retries. No URL-token signature downgrade.
- Missing/unavailable optional hooks discovery no longer blocks checkout if gateways availability returns a nonempty list. Not proof of authentication or payment: creation API and GET+verify remain mandatory. Other store callback never overwritten.
- RATE_LIMIT_EXCEEDED user guidance; INVALID_API_KEY support. Existing independent orders, cashback, KYC (SibilPay included / TetraPay excluded), late review and other gateways unchanged.
Local regression is not live bank/crypto settlement certification. Production diagnosis uses GET only; creating paid test invoices needs explicit operator approval due creation fee.
