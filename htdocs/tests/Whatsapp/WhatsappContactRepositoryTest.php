<?php

declare(strict_types=1);

namespace App\Tests\Whatsapp;

use App\Repositories\WhatsappContactRepository;
use PDO;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';

final class WhatsappContactRepositoryTest
{
    private PDO $pdo;
    private WhatsappContactRepository $contacts;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->bootstrapSchema();

        $this->contacts = new WhatsappContactRepository($this->pdo);
    }

    public function run(): void
    {
        $this->testListForSnapshotRefresh();
        echo "WhatsappContactRepositoryTest: OK" . PHP_EOL;
    }

    private function testListForSnapshotRefresh(): void
    {
        $now = time();
        $staleTs = $now - 900000; // >10 dias

        // Sem snapshot -> deve entrar.
        $this->insertContact('5501999999999', null, $staleTs);

        // Snapshot fresco -> não deve entrar.
        $freshMeta = json_encode([
            'gateway_snapshot' => ['profile_photo' => 'http://cdn/p1.jpg', 'captured_at' => $now],
            'gateway_snapshot_at' => $now,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->insertContact('5501888888888', $freshMeta, $now);

        // Snapshot antigo -> deve entrar.
        $oldMeta = json_encode([
            'gateway_snapshot' => ['profile_photo' => 'http://cdn/p2.jpg', 'captured_at' => $staleTs],
            'gateway_snapshot_at' => $staleTs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->insertContact('5501777777777', $oldMeta, $staleTs);

        $result = $this->contacts->listForSnapshotRefresh($now - 604800, 10);
        $phones = array_map(static fn(array $row): string => (string)$row['phone'], $result);

        $this->assertTrue(in_array('5501999999999', $phones, true), 'Contato sem snapshot deve ser listado.');
        $this->assertTrue(in_array('5501777777777', $phones, true), 'Snapshot antigo deve ser listado.');
        $this->assertFalse(in_array('5501888888888', $phones, true), 'Snapshot recente não deve ser listado.');
    }

    private function insertContact(string $phone, ?string $metadata, int $updatedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO whatsapp_contacts (client_id, name, phone, tags, preferred_language, last_interaction_at, metadata, created_at, updated_at)
             VALUES (NULL, :name, :phone, NULL, "pt-BR", :last_interaction_at, :metadata, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => 'Contato WhatsApp',
            ':phone' => $phone,
            ':last_interaction_at' => $updatedAt,
            ':metadata' => $metadata,
            ':created_at' => $updatedAt,
            ':updated_at' => $updatedAt,
        ]);
    }

    private function bootstrapSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE whatsapp_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NULL,
                name TEXT NOT NULL,
                phone TEXT NOT NULL UNIQUE,
                tags TEXT NULL,
                preferred_language TEXT NULL,
                last_interaction_at INTEGER NULL,
                metadata TEXT NULL,
                created_at INTEGER NULL,
                updated_at INTEGER NULL
            )'
        );
    }

    private function assertTrue($value, string $message = ''): void
    {
        if (!$value) {
            throw new \RuntimeException($message !== '' ? $message : 'Falha no assertTrue');
        }
    }

    private function assertFalse($value, string $message = ''): void
    {
        if ($value) {
            throw new \RuntimeException($message !== '' ? $message : 'Falha no assertFalse');
        }
    }
}

$test = new WhatsappContactRepositoryTest();
$test->run();
