<?php

declare(strict_types=1);

namespace App\Tests\Finance;

use App\Repositories\Finance\FinancialTransactionRepository;
use App\Repositories\Finance\TransactionImportRepository;
use App\Services\Finance\TransactionImportReviewService;
use PDO;
use RuntimeException;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';

final class TransactionImportReviewServiceTest
{
    private PDO $pdo;
    private TransactionImportRepository $imports;
    private FinancialTransactionRepository $transactions;
    private TransactionImportReviewService $service;
    private int $batchId;
    private int $rowNumber = 1;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->bootstrapSchema();

        $this->imports = new TransactionImportRepository($this->pdo);
        $this->transactions = new FinancialTransactionRepository($this->pdo);
        $this->service = new TransactionImportReviewService($this->imports, $this->transactions);

        $this->seedAccount();
        $this->batchId = $this->seedBatch();
    }

    public function run(): void
    {
        $this->testSuccessfulImport();
        $this->testDuplicateDetectionAndOverride();
        $this->testSkipRow();

        echo "TransactionImportReviewServiceTest: OK" . PHP_EOL;
    }

    private function bootstrapSchema(): void
    {
        $migrations = [
            '2025_11_30_090000_create_financial_accounts_table.php',
            '2025_11_30_090500_create_financial_transactions_table.php',
            '2025_11_30_110000_create_transaction_import_tables.php',
            '2025_12_01_130000_add_import_fields_to_financial_transactions.php',
            '2025_12_01_140000_extend_transaction_import_rows_for_review.php',
        ];

        foreach ($migrations as $file) {
            $migration = require base_path('app/Database/Migrations/' . $file);
            $migration->up($this->pdo);
        }
    }

    private function seedAccount(): void
    {
        $now = now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO financial_accounts (display_name, institution, current_balance, available_balance, created_at, updated_at)
             VALUES (:display_name, :institution, :current_balance, :available_balance, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':display_name' => 'Conta Teste',
            ':institution' => 'Banco XPTO',
            ':current_balance' => 0,
            ':available_balance' => 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function seedBatch(): int
    {
        $metadata = json_encode([
            'file_type' => 'ofx',
            'timezone' => 'America/Sao_Paulo',
        ], JSON_UNESCAPED_UNICODE);

        return $this->imports->createBatch([
            'account_id' => 1,
            'filename' => 'teste.ofx',
            'filepath' => storage_path('tests/teste.ofx'),
            'status' => 'ready',
            'total_rows' => 0,
            'processed_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'imported_rows' => 0,
            'failed_rows' => 0,
            'metadata' => $metadata,
        ]);
    }

    private function testSuccessfulImport(): void
    {
        $checksum = $this->checksum('primeira-linha');
        $rowId = $this->insertRow($checksum);

        $result = $this->service->importRows($this->batchId, [$rowId]);
        $this->assertEquals(1, $result['imported'] ?? null, 'Deve importar uma linha.');
        $this->assertEquals(0, $result['errors'] ?? null, 'Não deve registrar erros.');

        $row = $this->imports->rowById($this->batchId, $rowId);
        $this->assertEquals('imported', $row['status'] ?? null, 'Linha deve ficar com status imported.');
        $this->assertTrue(!empty($row['transaction_id']), 'Linha importada deve conter transaction_id.');

        $transaction = $this->transactions->find((int)$row['transaction_id']);
        $this->assertEquals($rowId, $transaction['import_row_id'] ?? null, 'Transação deve referenciar import_row_id.');
        $this->assertEquals($checksum, $transaction['checksum'] ?? null, 'Checksum deve ser preservado.');

        $batch = $this->imports->findBatch($this->batchId);
        $this->assertEquals('completed', $batch['status'] ?? null, 'Batch deve ser marcado como completed.');
        $this->assertEquals(1, $batch['imported_rows'] ?? null, 'Contador de importadas deve ser incrementado.');
    }

    private function testDuplicateDetectionAndOverride(): void
    {
        $checksum = $this->checksum('primeira-linha');
        $duplicateRowId = $this->insertRow($checksum);

        $result = $this->service->importRows($this->batchId, [$duplicateRowId]);
        $this->assertEquals(0, $result['imported'] ?? null, 'Duplicado não deve importar.');
        $this->assertEquals(1, $result['duplicates'] ?? null, 'Duplicado deve ser contabilizado.');

        $row = $this->imports->rowById($this->batchId, $duplicateRowId);
        $this->assertEquals('error', $row['status'] ?? null, 'Duplicado deve ficar como erro.');
        $this->assertEquals('duplicate_existing', $row['error_code'] ?? null, 'Erro deve indicar duplicidade.');

        $overrideRowId = $this->insertRow($checksum);
        $overrideResult = $this->service->importRows($this->batchId, [$overrideRowId], true);
        $this->assertEquals(1, $overrideResult['imported'] ?? null, 'Override deve importar.');
        $rowOverride = $this->imports->rowById($this->batchId, $overrideRowId);
        $this->assertEquals('imported', $rowOverride['status'] ?? null, 'Linha com override deve ser importada.');
    }

    private function testSkipRow(): void
    {
        $rowId = $this->insertRow($this->checksum('pular-linha'));
        $result = $this->service->skipRow($this->batchId, $rowId, 'Dados inválidos');

        $this->assertEquals('skipped', $result['status'] ?? null, 'Retorno deve indicar status skipped.');

        $row = $this->imports->rowById($this->batchId, $rowId);
        $this->assertEquals('skipped', $row['status'] ?? null, 'Linha precisa estar marcada como skipped.');
        $this->assertEquals('skipped_manual', $row['error_code'] ?? null, 'Código de erro deve registrar skip manual.');
        $this->assertEquals('Dados inválidos', $row['error_message'] ?? null, 'Motivo customizado deve ser salvo.');
    }

    private function insertRow(string $checksum): int
    {
        $payload = [
            'row_number' => $this->rowNumber++,
            'status' => 'valid',
            'transaction_type' => 'credit',
            'amount_cents' => 1000,
            'occurred_at' => now(),
            'description' => 'Venda teste',
            'reference' => 'REF-' . substr($checksum, 0, 6),
            'checksum' => $checksum,
            'raw_payload' => json_encode(['description' => 'Venda teste'], JSON_UNESCAPED_UNICODE),
            'normalized_payload' => json_encode([
                'transaction_type' => 'credit',
                'amount_cents' => 1000,
                'occurred_at' => now(),
                'description' => 'Venda teste',
                'reference' => 'REF-' . substr($checksum, 0, 6),
                'signed_amount_cents' => 1000,
                'checksum' => $checksum,
            ], JSON_UNESCAPED_UNICODE),
            'error_code' => null,
            'error_message' => null,
            'transaction_id' => null,
            'imported_at' => null,
        ];

        $this->imports->insertRows($this->batchId, [$payload]);
        return (int)$this->pdo->lastInsertId();
    }

    private function checksum(string $seed): string
    {
        return hash('sha256', $seed);
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . sprintf(' (esperado %s, obtido %s)', var_export($expected, true), var_export($actual, true)));
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$test = new TransactionImportReviewServiceTest();
$test->run();
