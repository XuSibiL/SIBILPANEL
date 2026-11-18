# 3.18.17 — SibilPay invoice response compatibility

Actual production API returns suffixes larger than the old documented 999-rial limit (1441, 6214, 7067 observed), and UTC ISO timestamps without timezone offsets. Old merchants rejected real invoices, then interpreted expiry in local Tehran time.
- Remove undocumented client-side suffix upper bound. The authenticated API defines payable_rial; it must remain integer and >= exact local principal. Once adopted it remains immutable. Credit/cashback are still based on local principal, never the suffix.
- Parse naive API ISO dates as UTC; honor explicit offsets. Keep type, identity, mode, URL and expiry validation; GET + verify and delivery idempotence unchanged.
- Display name: سیبیل پی (آنی:ریالی-ارزی). Existing callbacks/settings/financial history unchanged.
- Existing unknown orders recover using GET by-order, never a new POST. Expired or paid orders do not get a fresh payment link blindly.
- KYC aggregation still includes SibilPay and excludes TetraPay. No restoration of stale financial DB.
- UTC-naive interpretation follows observed provider timestamps; docs12 still describes the older suffix range. No live settlement certification.
