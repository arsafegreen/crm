<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    private PDO $pdo;
    private string $path;

    public function __construct(?PDO $pdo = null, ?string $path = null, ?string $connectionName = null)
    {
        $this->pdo = $pdo ?? Connection::instance($connectionName);
        $this->path = $path ?? __DIR__ . '/Migrations';
        $this->ensureMigrationsTable();
    }

    public function run(): void
    {
        $migrations = glob($this->path . '/*.php') ?: [];
        sort($migrations);

        foreach ($migrations as $file) {
            $name = basename($file, '.php');

            if ($this->migrationAlreadyRan($name)) {
                continue;
            }

            /** @var Migration $migration */
            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw new RuntimeException(sprintf('Migration %s must return an instance of %s.', $name, Migration::class));
            }

            $this->pdo->beginTransaction();
            try {
                $migration->up($this->pdo);
                $this->markAsRan($name);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                ran_at INTEGER NOT NULL
            )'
        );
    }

    private function migrationAlreadyRan(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM migrations WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);

        return (bool) $stmt->fetchColumn();
    }

    private function markAsRan(string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO migrations (name, ran_at) VALUES (:name, :ran_at)');
        $stmt->execute([
            ':name' => $name,
            ':ran_at' => time(),
        ]);
    }
}
