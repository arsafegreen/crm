<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS copilot_manuals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NULL,
                filename TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                content_preview TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS copilot_manual_chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                manual_id INTEGER NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                tokens_estimate INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (manual_id) REFERENCES copilot_manuals(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS copilot_training_samples (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NULL,
                contact_name TEXT NOT NULL,
                contact_phone TEXT NULL,
                category TEXT NOT NULL,
                summary TEXT NULL,
                messages_json TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_copilot_manual_chunks_manual ON copilot_manual_chunks(manual_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_copilot_manual_chunks_tokens ON copilot_manual_chunks(tokens_estimate)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_copilot_training_samples_category ON copilot_training_samples(category)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_copilot_training_samples_thread ON copilot_training_samples(thread_id)');
    }
};
