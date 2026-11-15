# 3.18.10 — wallet suspension recovery and early warnings

Base: a4fc4fee0ce2826e7606e61318d95a419e0f0d67 (3.18.9).

## Fixes
- The usage-meter suspension path previously failed to persist a balance reason.
  Some recovery paths erased that reason *before* a successful remote activation.
  Status calls swallowed failures and the local row could falsely say active.
- Every new suspension now retains a classified reason. Only balance-related holds
  auto-resume. Manual, expired, traffic-exhausted, deleted and unclassified holds do not.
- Successful top-ups, admin credits and all other wallet UPDATE paths atomically
  enqueue eligible panels via SQLite triggers, including the idempotent top-up path.
  There is no provider or Telegram request inside the money transaction.
- A bounded background worker reacts after an update batch / HTTP shutdown, independent
  of the five-minute usage meter. One panel/provider failure does not stop siblings.
  Failure retains the hold and retries with backoff; a successful HTTP provider change
  is required before local activation or a success message. Actual availability and
  latency still depend on the panel API and the server's background process support.
- Per-panel process locks serialize meter charging, suspension, recovery and manual
  commands. Recovery restarts only the paused hourly baseline; billed traffic, past
  spend, usage counters, expiry, credentials and sold limits remain unchanged.
- Hourly unlimited products still settle real pending live-minute debt before resuming;
  a positive remaining wallet is required. Legacy Role-managed unlimited products and
  free trials retain their existing billing exemptions.

## Warnings
Crossing 90,000, 50,000 and 10,000 Toman sends a warning once per descent. A credit
above a threshold rearms that threshold. A single large debit crossing several
thresholds produces only the most urgent warning, not three obsolete messages.
Zero balance is handled by the suspension notice, not a late positive-balance warning.
Time remaining = current account wallet / actual aggregate usage charges per hour,
using up to 23 hours of available panel history (minimum one hour). Deposits, purchases,
refunds and admin withdrawals are not usage. All the user's panels contribute.
No data => no invented estimate. It is an estimate, not a promise of future consumption;
postpaid charge batches, prepaid windows and changing traffic/rates can affect accuracy.

## Compatibility / operations
The migration adds queue tables, indexes and triggers. It does not replay payments,
restore databases or infer that an old unclassified hold was financial. Such a hold
needs one manual verification; silently overriding a possible manual block is unsafe.
All new balance holds are tagged and recover automatically.
Back up DB/config, update normally to 3.18.10, then test a small real top-up and status
change on the actual PasarGuard API. Verify background PHP process creation is allowed
(shell_exec/nohup), the bot account can write PANEL_HOME, and the provider is reachable.
`php sibil.php --wallet-worker` runs the bounded queue manually for health checks.
Code rollback should not restore an older DB over newer payments. Queue tables and
ledger data can remain for audit. No real Telegram/provider end-to-end test is claimed.
