<?php

declare(strict_types=1);

namespace App\Tests\Marketing;

use App\Repositories\ImportLogRepository;
use App\Repositories\Marketing\AudienceListRepository;
use App\Repositories\Marketing\ContactAttributeRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use App\Services\Marketing\MarketingContactImportService;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';

final class MarketingContactImportServiceTest
{
    private PDO $pdo;
    private AudienceListRepository $lists;
    private MarketingContactRepository $contacts;
    private ContactAttributeRepository $attributes;
    private ImportLogRepository $logs;
    private MarketingContactImportService $service;
    private int $defaultListId;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->bootstrapSchema();

        $this->lists = new AudienceListRepository($this->pdo);
        $this->contacts = new MarketingContactRepository($this->pdo);
        $this->attributes = new ContactAttributeRepository($this->pdo);
        $this->logs = new ImportLogRepository($this->pdo);
        $this->service = new MarketingContactImportService(
            $this->contacts,
            $this->lists,
            $this->attributes,
            $this->logs,
            500
        );

        $this->defaultListId = $this->lists->create([
            'name' => 'Lista Base',
            'slug' => 'lista-base',
            'status' => 'active',
        ]);
    }

    public function run(): void
    {
        $this->testCreatesAndUpdatesContacts();
        $this->testDuplicateRowsAreReported();

        echo 'MarketingContactImportServiceTest: OK' . PHP_EOL;
    }

    private function testCreatesAndUpdatesContacts(): void
    {
        $this->contacts->create([
            'email' => 'joao@example.com',
            'first_name' => 'João',
            'status' => 'active',
            'consent_status' => 'pending',
            'tags' => 'vip',
        ]);

        $csv = $this->createCsv([
            [
                'email' => 'ana@example.com',
                'first_name' => 'Ana',
                'last_name' => 'Souza',
                'tags' => 'lead;beta',
                'consent_status' => 'confirmed',
                'consent_source' => 'evento_rd',
                'consent_at' => '2025-02-10',
                'custom.segment' => 'enterprise',
            ],
            [
                'email' => 'joao@example.com',
                'first_name' => 'João Atualizado',
                'tags' => 'vip;hotsite',
                'consent_status' => 'opted_out',
                'custom.score' => '85',
            ],
        ]);

        $result = $this->service->import($this->defaultListId, $csv, [
            'source_label' => 'landing_2025',
            'respect_opt_out' => true,
            'user_id' => null,
            'filename' => 'contatos.csv',
        ]);

        @unlink($csv);

        $stats = $result['stats'] ?? [];
        $this->assertEquals(2, $stats['processed'] ?? null, 'Deve processar duas linhas.');
        $this->assertEquals(1, $stats['created_contacts'] ?? null, 'Deve criar um contato novo.');
        $this->assertEquals(1, $stats['updated_contacts'] ?? null, 'Deve atualizar contato existente.');
        $this->assertEquals(0, $stats['invalid'] ?? null, 'Não deve marcar linhas inválidas.');

        $ana = $this->contacts->findByEmail('ana@example.com');
        $this->assertTrue($ana !== null, 'Contato Ana precisa existir.');
        $this->assertEquals('Ana', $ana['first_name'] ?? null, 'Primeiro nome deve ser mantido.');
        $this->assertEquals('confirmed', $ana['consent_status'] ?? null, 'Consentimento precisa marcar confirmed.');
        $this->assertEquals('lead,beta', $ana['tags'] ?? null, 'Tags devem ser normalizadas e mescladas.');
        $this->assertTrue(($ana['consent_at'] ?? null) !== null, 'Consentimento confirmado deve registrar data.');

        $attributes = $this->fetchAttributes((int)$ana['id']);
        $this->assertEquals('enterprise', $attributes['segment'] ?? null, 'Atributo customizado deve ser salvo.');

        $joao = $this->contacts->findByEmail('joao@example.com');
        $this->assertEquals('João Atualizado', $joao['first_name'] ?? null, 'Contato existente deve ser atualizado.');
        $this->assertEquals('opted_out', $joao['consent_status'] ?? null, 'Contato deve ficar opt-out.');
        $this->assertEquals('vip,hotsite', $joao['tags'] ?? null, 'Tags devem mesclar sem duplicar.');

        $listContacts = $this->listContactsByEmail($this->defaultListId);
        $this->assertEquals(2, count($listContacts), 'Lista precisa conter dois vínculos.');
        $this->assertEquals('subscribed', $listContacts['ana@example.com']['subscription_status'], 'Contato novo deve estar inscrito.');
        $this->assertEquals('unsubscribed', $listContacts['joao@example.com']['subscription_status'], 'Contato opt-out deve ficar unsubscribed.');

        $logs = $this->pdo->query('SELECT * FROM import_logs')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $this->assertEquals(1, count($logs), 'Importação deve escrever log.');
        $this->assertEquals('marketing_contacts', $logs[0]['source'] ?? null, 'Fonte do log deve ser marketing.');
        $this->assertEquals(2, $logs[0]['processed'] ?? null, 'Log precisa registrar processados.');
    }

    private function testDuplicateRowsAreReported(): void
    {
        $listId = $this->lists->create([
            'name' => 'Lista Duplicados',
            'slug' => 'lista-duplicados',
            'status' => 'active',
        ]);

        $csv = $this->createCsv([
            ['email' => 'dup@example.com', 'first_name' => 'Primeiro'],
            ['email' => 'dup@example.com', 'first_name' => 'Segundo'],
        ]);

        $result = $this->service->import($listId, $csv, []);
        @unlink($csv);

        $stats = $result['stats'] ?? [];
        $this->assertEquals(2, $stats['processed'] ?? null, 'Mesmo com duplicidade deve contar ambas linhas.');
        $this->assertEquals(1, $stats['duplicates_in_file'] ?? null, 'Deve registrar duplicidade em arquivo.');
        $this->assertEquals(1, $stats['created_contacts'] ?? null, 'Apenas um contato deve ser criado.');
        $this->assertEquals(1, count($result['errors'] ?? []), 'Deve haver erro apontando duplicidade.');
    }

    private function createCsv(array $rows): string
    {
        if ($rows === []) {
            throw new RuntimeException('Informe pelo menos uma linha para gerar CSV.');
        }

        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $headers, true)) {
                    $headers[] = $column;
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'marketing_csv_');
        if ($path === false) {
            throw new RuntimeException('Não foi possível gerar arquivo temporário.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Falha ao abrir arquivo temporário.');
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);
        return $path;
    }

    private function fetchAttributes(int $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT attribute_key, attribute_value FROM marketing_contact_attributes WHERE contact_id = :id');
        $stmt->execute([':id' => $contactId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[$row['attribute_key']] = $row['attribute_value'];
        }

        return $map;
    }

    private function listContactsByEmail(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lc.*, c.email FROM audience_list_contacts lc
             INNER JOIN marketing_contacts c ON c.id = lc.contact_id
             WHERE lc.list_id = :id'
        );
        $stmt->execute([':id' => $listId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[$row['email']] = $row;
        }

        return $map;
    }

    private function bootstrapSchema(): void
    {
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        $this->pdo->exec(
            'CREATE TABLE audience_lists (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT NULL,
                origin TEXT NULL,
                purpose TEXT NULL,
                consent_mode TEXT NOT NULL DEFAULT "single_opt_in",
                double_opt_in INTEGER NOT NULL DEFAULT 0,
                opt_in_statement TEXT NULL,
                retention_policy TEXT NULL,
                status TEXT NOT NULL DEFAULT "active",
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                archived_at INTEGER NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE marketing_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                crm_client_id INTEGER NULL,
                email TEXT NOT NULL,
                phone TEXT NULL,
                first_name TEXT NULL,
                last_name TEXT NULL,
                locale TEXT NOT NULL DEFAULT "pt_BR",
                timezone TEXT NULL,
                status TEXT NOT NULL DEFAULT "active",
                consent_status TEXT NOT NULL DEFAULT "pending",
                consent_source TEXT NULL,
                consent_at INTEGER NULL,
                opt_out_at INTEGER NULL,
                suppression_reason TEXT NULL,
                bounce_count INTEGER NOT NULL DEFAULT 0,
                complaint_count INTEGER NOT NULL DEFAULT 0,
                tags TEXT NULL,
                last_interaction_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (crm_client_id) REFERENCES clients(id) ON DELETE SET NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE audience_list_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                list_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                subscription_status TEXT NOT NULL DEFAULT "subscribed",
                source TEXT NULL,
                subscribed_at INTEGER NOT NULL,
                unsubscribed_at INTEGER NULL,
                consent_token TEXT NULL,
                metadata TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (list_id) REFERENCES audience_lists(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE
            )'
        );
        $this->pdo->exec('CREATE UNIQUE INDEX idx_audience_list_contacts_unique ON audience_list_contacts(list_id, contact_id)');

        $this->pdo->exec(
            'CREATE TABLE marketing_contact_attributes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id INTEGER NOT NULL,
                attribute_key TEXT NOT NULL,
                attribute_value TEXT NULL,
                value_type TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE
            )'
        );
        $this->pdo->exec('CREATE UNIQUE INDEX idx_contact_attributes_unique ON marketing_contact_attributes(contact_id, attribute_key)');

        $this->pdo->exec(
            'CREATE TABLE import_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                source TEXT NOT NULL,
                filename TEXT NOT NULL,
                processed INTEGER NOT NULL DEFAULT 0,
                created_clients INTEGER NOT NULL DEFAULT 0,
                updated_clients INTEGER NOT NULL DEFAULT 0,
                created_certificates INTEGER NOT NULL DEFAULT 0,
                updated_certificates INTEGER NOT NULL DEFAULT 0,
                skipped INTEGER NOT NULL DEFAULT 0,
                skipped_older INTEGER NOT NULL DEFAULT 0,
                meta TEXT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );
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

$test = new MarketingContactImportServiceTest();
$test->run();
