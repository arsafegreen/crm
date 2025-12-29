# Finance Import Review Actions — Design Notes

_Last updated: 2025-12-01_

## 1. Goals
- Allow operators to import or skip validated rows directly from `Finance → Importações → Lote`.
- Link every inserted `financial_transactions` record to `transaction_import_rows.import_row_id` and persist the `checksum` for traceability/deduplication.
- Prevent duplicates across batches by reusing the `checksum` column we just added to `financial_transactions`.
- Keep batch/row counters, status transitions, and audit events consistent with the roadmap defined in `docs/finance_importer_plan.md`.

## 2. Endpoints & Permissions
| Route | Purpose | Payload (POST) | Notes |
| --- | --- | --- | --- |
| `POST /finance/imports/{batch}/rows/import` | Import validated rows into `financial_transactions`. | `row_ids[]` (optional), `mode` (`selected`\|`all`), `import_options[override_duplicates]` bool | Requires `finance.imports` permission; when `row_ids` omitted and `mode=all`, import all rows with status `valid`.
| `POST /finance/imports/{batch}/rows/{row}/skip` | Mark a single row as skipped (won't be imported). | `reason` (optional string) | Limited to rows in `valid` or `invalid` state; stores reason in `error_message`.
| `POST /finance/imports/{batch}/rows/{row}/unskip` (optional) | Return a skipped row to `valid` state for re-review. | — | Enables undo without re-running parser; implement if UI requires it.
| `POST /finance/imports/{batch}/rows/refresh` | Re-evaluate duplicates against live ledger. | — | Lightweight endpoint to resync duplicate flags without reparsing; optional but nice-to-have.

**Controller integration**
- Extend `FinanceImportController` with the new action methods (`importRows`, `skipRow`, `unskipRow`, `refreshRows`).
- Flash feedback through `finance_imports_batch_feedback` so UI can surface success/errors near the batch detail view.

## 3. Service Responsibilities
Introduce `TransactionImportReviewService` to keep parsing and review logic separate from `TransactionImportService`.

### `TransactionImportReviewService::importRows(int $batchId, ?array $rowIds = null, bool $overrideDuplicates = false)`
1. Load batch (`TransactionImportRepository::findBatch`). Ensure status is `ready` or `importing`.
2. Fetch target rows:
   - When `$rowIds` provided → `rowsForImport($batchId, $rowIds)`.
   - Otherwise → `rowsForImport($batchId)` returning every `valid` row not yet imported/skipped.
3. For each row:
   - Decode `normalized_payload` (must contain `transaction_type`, `amount_cents`, `occurred_at`, etc.).
   - Build transaction payload (account id from batch, optional cost center metadata).
   - Compute or reuse checksum; skip if missing (row becomes `error`).
   - Duplicate detection:
     - Query `FinancialTransactionRepository::findByChecksum($checksum)` scoped to same account, limited to recent 90 days.
     - If found and `$overrideDuplicates` is false → mark row `error` (code `duplicate_existing`).
   - Insert transaction via `FinancialTransactionRepository::create` with `import_row_id` + `checksum`.
   - Set row status to `imported`, fill `transaction_id` reference (new nullable column on rows) and `imported_at` timestamp.
   - Collect affected account IDs to batch recalculation later.
4. After loop:
   - Recalculate balances once per affected account using `FinancialTransactionRepository::recalculateBalance`.
   - Update batch counters (`imported_rows`, `failed_rows`, `processed_rows`) and switch status: `ready → importing → completed` when no rows remain in `valid` state.
   - Record events summarizing success/failure counts.

### `TransactionImportReviewService::skipRow(int $batchId, int $rowId, ?string $reason = null)`
- Validate row belongs to batch and is not already `imported`.
- Update row status to `skipped`, populate `error_code = 'skipped_manual'` and message (reason).
- Adjust batch counters (`invalid_rows` maybe unaffected; track `failed_rows`).
- Emit event `row_skipped`.

### `TransactionImportReviewService::unskipRow(...)`
- Only if we implement undo: change status back to `valid` and clear error message.

### `TransactionImportReviewService::refreshDuplicates(int $batchId)`
- Optional utility to re-check `valid` rows against ledger using checksum queries, marking them with an informational flag in `normalized_payload` or a new column `duplicate_hint`.

## 4. Repository Additions
### `TransactionImportRepository`
- `rowsForImport(int $batchId, ?array $rowIds = null): array`
  - Returns rows with columns needed for import (id, normalized_payload, checksum, status).
  - Enforce `status = 'valid'` and `imported_at IS NULL`.
- `updateRows(array $rows): void`
  - Batch update helper for status, error info, `transaction_id`, timestamps.
- `rowById(int $batchId, int $rowId): ?array`
  - Used by skip/unskip endpoints.
- `sumStatusCounters(int $batchId): array`
  - Aggregates counts to keep batch stats accurate after manual actions.

### `FinancialTransactionRepository`
- `findByChecksum(int $accountId, string $checksum, int $sinceTimestamp): ?array`
  - Speeds up duplicate detection.
- `bulkInsert(array $payloads): array`
  - Optional optimization if we decide to insert multiple transactions per request.

## 5. Data Model Touchpoints
- `financial_transactions`
  - Columns `import_row_id` + `checksum` already planned; ensure `checksum` index exists.
  - Consider partial unique index `(account_id, checksum)` if we want strict enforcement (SQLite supports partial uniqueness via `WHERE checksum IS NOT NULL`). For now, rely on service-level check.
- `transaction_import_rows`
  - Add nullable `transaction_id` + `imported_at` fields for traceability.
  - Maintain `status` enum expansion (`valid`, `invalid`, `imported`, `skipped`, `error`).

## 6. Status & Event Rules
- Row transitions:
  - `valid → imported` (success)
  - `valid → error` (duplicate, insertion failure)
  - `valid|invalid → skipped`
  - `skipped → valid` (unskip)
- Batch transitions:
  - `ready → importing` once the first row import begins.
  - `importing → completed` when `valid` count hits zero and no pending rows remain.
  - `ready/importing → failed` if import endpoint throws before any row succeeds.
- Event payloads (stored in `transaction_import_events`):
  - `row_imported` (row id, transaction id, checksum)
  - `row_skipped`
  - `duplicate_blocked`
  - `batch_status_changed`

## 7. UI Hooks
- **Valid rows table** (`resources/views/finance/imports/show.php`):
  - Add per-row actions: `Importar`, `Pular`. Use small forms posting to the new endpoints with CSRF tokens.
  - Provide bulk action bar with checkbox selection + `Importar selecionadas` / `Pular selecionadas` (phase 2).
  - Display duplicate warnings when backend flags them (e.g., badge "Possível duplicado" near description).
- **Feedback messaging**: after each action, show flash from `finance_imports_batch_feedback` summarizing counts.

## 8. Validation & Error Handling
- Reject requests when batch/row not found or statuses incompatible (HTTP 404/422 translated to flash messages).
- Wrap `importRows` in transaction per batch to avoid partial counters when exceptions occur; still commit already-created financial transactions to keep ledger consistent.
- Ensure CSRF protection is enforced since endpoints mutate state.

## 9. Next Implementation Steps
1. Extend migrations to add `transaction_id`, `imported_at`, `status` defaults, and indexes for `transaction_import_rows` plus `checksum` on `financial_transactions` (already done).
2. Implement `TransactionImportReviewService` + repository helpers.
3. Wire routes/controller actions and update AuthGuard permissions.
4. Update `finance/imports/show.php` with new forms/buttons and duplicate badges.
5. Add feature tests covering import + skip flows, duplicate handling, and batch counter updates.
