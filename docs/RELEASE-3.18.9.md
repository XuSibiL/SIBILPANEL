# SIBILPANEL 3.18.9

Base: b0e859d879db55f6285b8b36d3a3b83d72d4aae7 (3.18.8).

## Diagnosis
Polling did not subscribe to pre_checkout_query and the dispatcher never answered it.
Telegram requires an answer within ten seconds. This missing handshake explains a
server-side confirmation failure; a client error label alone does not prove its cause.
Normal chat work in a batch now follows pre-checkouts. The Telegram answer HTTP
budget is seven seconds. No remote rate/provider request occurs in validation.
A successful paid receipt is processed before membership and blocked-user UI gates.
Buyer, stored order/payment, currency, exact snapshot amount, TTL and daily capacity
are checked. Atomic pay_accept/pay_settle and duplicate protection are retained.

## Customer login suffix
Additive pg_panels.dashboard_path, default /dashboard/. Existing API origin and key
are unchanged. The link builder joins origin + explicit port + validated suffix;
old base path fragments are not duplicated. Purchase/trial delivery and My Panels
read the connected panel's value. Callback and wizard save both require full RBAC.

## Validation
Run bash tests/run_all.sh. Includes 57 dedicated offline regressions plus existing
Iranian gateway/KYC/cashback, TetraPay, crypto lifecycle (NOWPayments/Plisio), RBAC,
force-join, trials, unlimited billing, installer, DB import and rollback regressions.
No production credentials or real payment were used. Other gateway code paths were
not rewritten. Paid expired/closed orders retain existing late-payment handling.

## Deployment and rollback
Publishing changes GitHub only. Installed bots must use their normal update action;
back up the database/config first, restart normally and verify version 3.18.9.
Test a small Stars invoice and a custom login URL on the actual installation.
Pre-checkout acceptance does not charge/credit by itself. Insufficient Telegram
Stars still requires topping up. Do not replay paid receipts manually.
Rollback code to the prior known commit with the standard update/backup mechanism;
the extra additive dashboard_path column is compatible with old code. Do not restore
an old database over newer payments. New installs continue to use /dashboard/.
