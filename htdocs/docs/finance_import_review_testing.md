# Finance Import Review — Test & Verification Checklist

_Last updated: 2025-12-01_

## 1. Automated Smoke Test
1. Ensure dependencies are installed (`composer install`) and that PHP 8.2+ is available on PATH.
2. Run the in-memory service test:
   ```bash
   cd htdocs
   php tests/Finance/TransactionImportReviewServiceTest.php
   ```
3. The script bootstraps the required tables on SQLite memory, runs the review service against mocked rows, and prints `TransactionImportReviewServiceTest: OK` on success. Failures throw a descriptive exception explaining which assertion broke.

_Coverage notes:_
- Successful import flow (row → financial_transactions with checksum/import_row_id linkage).
- Duplicate detection vs. override flag.
- Manual skip action persisting reason/error metadata.

## 2. Manual Verification (UI)
1. **Prepare batch**
   - Upload an OFX/CSV file via `Finance → Importações → Novo` and wait for parsing status `Pronto para importar`.
   - Confirm valid rows appear on the batch page (`/finance/imports/{id}`).
2. **Import selected rows**
   - Check a few rows in “Prontas para importar”, click `Importar` in the bulk bar.
   - Expect flash message summarizing imported/errors; the rows move to “Já inseridas”.
   - Validate resulting transactions in `Finance → Lançamentos` (checksum column now populated via DB).
3. **Import all rows**
   - Use the “Importar todas” button with and without “Ignorar duplicados detectados”.
   - When duplicates exist, confirm warning flash and row stays em “Com erros” with message `Já existe um lançamento com este checksum.`
4. **Skip row**
   - Click `Pular` beside a valid row, confirm confirmation modal, and verify row drops to the “Com erros” list with `skipped_manual` reason.
5. **Batch counters**
   - Observe header counters updating (`Validadas`, `Com erro`, `Inseridas`) and timeline showing the newest events (row_imported/row_skipped).
6. **Ledger impact**
   - For imported rows, open the financial account and ensure balances reflect the new entries (only one recalculation per batch). Use `Finance → Contas` recent list as sanity check.

## 3. Regression Watchlist
- Reprocess/retry existing batches to ensure new columns (`transaction_id`, `imported_at`) don’t break parsing.
- Validate permissions: users without `finance.imports` must receive HTTP 403 on new endpoints `/finance/imports/{id}/rows/import` and `/rows/{row}/skip`.
- SQLite migration order: run `php scripts/migrate.php` to apply the new migrations on deployed environments.
