<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pipelines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pipeline_stages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pipeline_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                is_closed INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (pipeline_id) REFERENCES pipelines(id) ON DELETE CASCADE
            )'
        );

        $timestamp = time();

        $pipelines = [
            ['Funil Comercial', 'Fluxo principal de prospecção e renovação'],
        ];

        $stages = [
            [1, 'Prospecção', 1, 0],
            [1, 'Contato Inicial', 2, 0],
            [1, 'Envio de Proposta', 3, 0],
            [1, 'Agendamento', 4, 0],
            [1, 'Emissão', 5, 0],
            [1, 'Pós-venda', 6, 0],
            [1, 'Perdido', 7, 1],
            [1, 'Renovado', 8, 1],
        ];

        $insertPipeline = $pdo->prepare('INSERT INTO pipelines (id, name, description, created_at, updated_at) VALUES (:id, :name, :description, :created_at, :updated_at)');
        foreach ($pipelines as $index => $data) {
            $insertPipeline->execute([
                ':id' => $index + 1,
                ':name' => $data[0],
                ':description' => $data[1],
                ':created_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]);
        }

        $insertStage = $pdo->prepare('INSERT INTO pipeline_stages (pipeline_id, name, position, is_closed, created_at, updated_at) VALUES (:pipeline_id, :name, :position, :is_closed, :created_at, :updated_at)');
        foreach ($stages as $stage) {
            $insertStage->execute([
                ':pipeline_id' => $stage[0],
                ':name' => $stage[1],
                ':position' => $stage[2],
                ':is_closed' => $stage[3],
                ':created_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]);
        }
    }
};
