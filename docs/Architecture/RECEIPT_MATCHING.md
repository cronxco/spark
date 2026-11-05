# Receipt matching

A receipt is an `Event` with `service=receipt` and `action=had_receipt_from`. Merchant `EventObject` rows are shared between receipts and must not hold match state, suggestions, or receipt-specific extraction data.

## Source of truth

An active `receipt_for` relationship from a receipt event to a bank transaction is the source of truth for whether the receipt is linked. The receipt's `event_metadata.receipt_matching` contains the current product state (`searching`, `suggestions`, `no_candidate`, `needs_details`, `no_match`, `dismissed`, `failed`, or `matched`), the last attempt time, reason, algorithm version, and candidate snapshots. `ReceiptMatchState::status()` returns `matched` whenever an active relationship exists, even if older metadata says otherwise.

The matcher locks the receipt row in a short transaction before creating a link and refuses a different second active link. This protects writes made through `ReceiptTransactionMatcher`; the schema has no uniqueness constraint, so direct relationship writes can still bypass the guard. A database constraint requires a separate schema decision.

## Triggers and recovery

Receipt creation runs `match_receipt_to_transaction`; arrival of an eligible bank transaction runs `find_receipt_for_transaction`. Both call `ReceiptTransactionMatcher::matchReceipt()`. A user retry queues the forward task with `force=true`, so a prior successful `no_candidate` execution does not suppress it. The reverse path can reconsider dismissed or explicitly unmatched receipts when new transaction evidence arrives, but only as a suggestion.

The web Receipts page and Receipt Detail expose retry, manual transaction search/link, unlink, and explicit no-match actions. The iOS Flint Review links to a paginated unmatched list and receipt matching detail through `/api/v1/mobile/flint/receipts/*`. Search is owner-scoped and accepts merchant text, an amount, or a `YYYY-MM-DD` date. Review suggestions are receipt-specific and only show owned candidate transactions.

## Historical sweep

`php artisan receipt-matching:backfill --limit=25` is a **read-only preview** that reports candidate confidence bands. `--dispatch` queues a bounded batch of `ReviewUnmatchedReceiptJob` jobs. These jobs can create suggestions but never historical automatic links. The command skips matched receipts, active suggestions, explicit no-match/dismissed receipts, and recently queued work. `--recent-days` and `--retry-after-days` control the window and cooldown. A scheduled daily run handles at most 25 receipts from the past 60 days with a seven-day retry cooldown.

For older receipts, use the preview first, inspect proposed matches, then run explicitly bounded batches. The current state is kept on each receipt; merchant flags from older code must not be copied as authoritative state. Do not bulk auto-link historical records from this command.
