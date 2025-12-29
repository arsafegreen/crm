# Finance Transaction Importer Plan

## 1. Objectives and Scope
- Accept OFX (default) and CSV spreadsheets exported from banks/partners and convert them into `financial_transactions` tied to existing accounts and cost centers.
- Provide auditors with full traceability: each imported transaction must reference the source batch/row and keep a checksum to prevent duplicates.
- Surface progress/failure information inside Finance > Overview and allow operators to reprocess or discard individual rows.
- Out of scope for Week-2: automatic bank connections, background workers, and tax matching.

## 2. User Flows
1. **Upload**
   - Path: `Finance → Contas → Importar extrato` (new link) or CTA on overview card.
   - Form fields: account (required), file type (OFX/CSV), timezone (defaults to America/Sao_Paulo), file upload, optional default cost center, optional category prefix, strategy for handling duplicates (`ignore`, `override desc`, `skip batch`).
   - POST `/finance/imports` validates file, stores it under `storage/app/finance-imports/{uuid}/`, creates a batch (`transaction_import_batches`). Status starts as `pending`.
2. **Parsing**
   - `ImportBatchProcessor` service immediately parses the file (synchronous for now) and inserts normalized rows into `transaction_import_rows`.
   - Each row contains the original payload (raw text or CSV line), normalized data, checksum, and default `pending` status. Batch counters (`total_rows`, `processed_rows`, etc.) update as we go.
3. **Review**
   - GET `/finance/imports/{batch}` shows batch header (file info, counters, duration) and a filterable table of rows grouped by status.
   - Operators can select rows and choose `Importar selecionados`, `Ignorar`, or adjust metadata (cost center, category, description) inline.
4. **Import**
   - POST `/finance/imports/{batch}/rows/apply` converts validated rows into `financial_transactions` using `FinancialTransactionRepository`. We store `import_row_id` (see Section 5) on each transaction to keep the linkage.
   - After each insert, balances are recalculated using the existing repository method.
   - Row statuses update to `imported` or `skipped`, and batch counters (`imported_rows`, `processed_rows`) increment. Batch status flips to `completed` when all rows leave `pending|valid` states.
5. **Failure & Resume**
   - If parsing fails, batch status becomes `failed` and an entry is added to `transaction_import_events`. Users can re-run processing via POST `/finance/imports/{batch}/retry` after fixing issues (e.g., uploading corrected CSV that overwrites the file path).
   - Manual cancellation is available through POST `/finance/imports/{batch}/cancel` (sets status `canceled`).

## 3. File Handling and Validation
- **Upload constraints**: max 5 MB, MIME types `application/ofx`, `application/x-ofx`, `text/xml`, `text/csv`, `text/plain`. Enforce server-side extension whitelist (`.ofx`, `.csv`, `.txt`).
- Files are saved with generated UUID filenames to avoid clashes and to ensure `filepath` stored in DB remains stable.
- CSV schema (default columns, order flexible using header detection):
  | Column         | Required | Notes |
  | -------------- | -------- | ----- |
  | `date`         | yes      | Parsed with timezone, format `dd/mm/yyyy` or ISO.
  | `time`         | no       | Combined with date; defaults to `00:00` if missing.
  | `description`  | yes      | Trimmed; appended with category prefix if configured.
  | `amount`       | yes      | Accepts `1.234,56` or `1234.56`; decimals normalized to cents.
  | `type`         | no       | If absent, infer using amount sign.
  | `reference`    | no       | Utilized for duplicate detection.
  | `cost_center`  | no       | Matches by code; fallback to default provided in form.
- OFX parsing: leverage PHP's DOM/SimpleXML since OFX is SGML-like. Steps: sanitize file (remove BOM), detect `OFXHEADER` vs XML, convert to DOM, iterate `STMTTRN` nodes extracting `TRNTYPE`, `DTPOSTED`, `TRNAMT`, `FITID`, `NAME`, `MEMO`.
- Validation rules per row:
  - Amount must be non-zero after normalization.
  - Date must convert to unix timestamp (use timezone from upload form).
  - Transaction type limited to `credit`/`debit` (map OFX `TRNTYPE` enumerations accordingly).
  - Duplicate detection uses checksum: `hash('sha256', account_id|timestamp|amount|normalized_description|reference)` and leverages the unique index on `(batch_id, checksum)` to prevent double inserts for the same batch. Before importing into `financial_transactions`, check whether any transaction already exists with the same checksum (new column) within the last 90 days to avoid cross-batch duplicates.

## 4. Batch and Row State Machine
- **Batch statuses**: `pending` → `processing` → `ready` → `importing` → `completed`. Terminal statuses: `failed`, `canceled`.
- **Row statuses**: `pending`, `validated`, `invalid`, `imported`, `skipped`, `error`.
- `ImportBatchProcessor` transitions rows from `pending` to either `validated` or `invalid`. UI actions move `validated` rows to `imported` or `skipped`. Errors thrown during insert mark row `error` with `error_code` and `error_message` populated.

## 5. Data Model Changes
1. **financial_transactions**
   - Add nullable columns `import_row_id` (INT, FK to `transaction_import_rows`) and `checksum` (TEXT, indexed) for traceability and deduplication.
2. **transaction_import_rows**
   - Ensure `raw_payload` and `normalized_payload` stay JSON strings for CSV and OFX (store arrays via `json_encode`).
   - Consider adding `account_id` snapshot to rows (even though the batch has one) to support future multi-account files. For now, we can keep account_id on batch only; row importer reads from batch.
3. **Events table** already exists; use it for audit log.

## 6. Application Components
- **Controllers**
  - Extend `FinanceController` or create `FinanceImportController`. Preference: new controller to keep FinanceController manageable.
  - Routes to add in `Kernel.php`:
    - `GET /finance/imports` → list batches.
    - `GET /finance/imports/create` → upload form.
    - `POST /finance/imports` → handle upload & parsing trigger.
    - `GET /finance/imports/{batch}` → batch detail & rows.
    - `POST /finance/imports/{batch}/retry`, `/cancel`, `/rows/apply`, `/rows/{row}/skip`.
- **Permissions**
  - Introduce `finance.imports` permission. Map overview/list actions to `finance.accounts` as fallback but require `finance.imports` for mutating endpoints (upload/process/cancel).
- **Services**
  - `ImportBatchService`: orchestrates upload validation, storage, and batch creation.
  - `OfxStatementParser` and `CsvStatementParser`: return generator/iterable of normalized row arrays. Provide shared helper for money/date parsing.
  - `TransactionRowValidator`: centralizes field validation + duplicate checks, writes errors back to repository.
  - `TransactionRowImporter`: takes validated rows, creates financial transactions, triggers `FinancialTransactionRepository::recalculateBalance` once per account after batch import (use set to avoid redundant recalcs per row).
- **Views** (under `resources/views/finance/imports/`)
  - `index.php`: table of batches with status pills, counters, CTAs.
  - `create.php`: upload form with field help.
  - `show.php`: batch summary, row filters, inline edit form, event log sidebar.
  - `partials/row_actions.php`: small component reused for actions.

## 7. Data Flow Summary
```
Upload Form -> FinanceImportController@store -> ImportBatchService
    -> Save file + create batch (status=pending)
    -> ImportBatchProcessor@parse
        -> Parser (OFX/CSV) yields raw rows
        -> Validator normalizes + checks -> TransactionImportRepository::insertRows
        -> Batch counters updated, status=ready
UI Review -> apply/cancel/skip actions
    -> TransactionRowImporter creates financial_transactions (+checksum/import_row_id)
    -> FinancialTransactionRepository::recalculateBalance
    -> Batch status toggles to importing/completed, overview widgets refresh via existing recentBatches() call
```

## 8. Error Handling & Observability
- Record every significant transition in `transaction_import_events` (`info`, `warning`, `error` levels). Examples: `file_saved`, `parsing_started`, `row_invalid`, `row_imported`, `batch_failed`.
- Surface last 20 events in the batch detail view for debugging.
- When parsing fails, include a short `error_message` plus `context` JSON containing filename, parser, offending row number.
- Add flash feedback keys:
  - `finance_imports_feedback` for list/upload pages.
  - `finance_import_rows_feedback` for batch detail actions.

## 9. Testing Strategy
- Unit-like tests (PHPUnit) for parser classes using fixtures: `tests/Fixtures/finance/ofx/basic.ofx`, `tests/Fixtures/finance/csv/basic.csv`.
- Repository tests verifying checksum uniqueness and `import_row_id` cascade delete.
- HTTP/controller tests for upload validation (missing file, invalid MIME, oversize) and for row import actions (ensuring balances update once per account).

## 10. Next Steps
1. Scaffold controller/routes/permissions and stub views (empty states) so navigation is visible.
2. Implement storage + batch creation workflow (no parser yet) to unblock UI.
3. Build parsers/validator/importer services iteratively, starting with OFX.
4. Add `import_row_id` + `checksum` columns/migration and wire transaction creation.
5. Finalize UX polish (filters, inline edits) and connect dashboard counters to new statuses.
