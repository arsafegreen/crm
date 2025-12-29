<?php

declare(strict_types=1);

use App\Database\Connection;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';
require __DIR__ . '/../bootstrap/app.php';

$force = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

if (!$force) {
    fwrite(STDERR, "Uso: php scripts/purge_chat_history.php --force [--dry-run]\n");
    fwrite(STDERR, "Adicione --dry-run para ver apenas os totais sem apagar os dados.\n");
    exit(1);
}

$pdo = Connection::instance();
$tables = [
    'chat_messages',
    'chat_participants',
    'chat_threads',
    'chat_message_purges',
];

$stats = [];
foreach ($tables as $table) {
    if (!tableExists($pdo, $table)) {
        $stats[$table] = 0;
        continue;
    }

    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM ' . $table);
    $stats[$table] = (int)($stmt?->fetchColumn() ?? 0);
}

fwrite(STDOUT, "Estado atual das tabelas:\n");
foreach ($stats as $table => $count) {
    fwrite(STDOUT, sprintf("- %s: %d registros\n", $table, $count));
}

if ($dryRun) {
    fwrite(STDOUT, "Execução em modo dry-run. Nenhum registro foi removido.\n");
    exit(0);
}

$pdo->beginTransaction();
$deleted = [];

try {
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table)) {
            $deleted[$table] = 0;
            continue;
        }

        $affected = $pdo->exec('DELETE FROM ' . $table);
        $deleted[$table] = $affected === false ? 0 : (int)$affected;
    }

    resetSequences($pdo, $tables);
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Falha ao limpar histórico: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Histórico removido com sucesso. Registros deletados:\n");
foreach ($deleted as $table => $count) {
    fwrite(STDOUT, sprintf("- %s: %d registros\n", $table, $count));
}

exit(0);

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    return $stmt->fetchColumn() !== false;
}

function resetSequences(PDO $pdo, array $tables): void
{
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $pdo->prepare('DELETE FROM sqlite_sequence WHERE name IN (' . $placeholders . ')');
    foreach ($tables as $index => $table) {
        $stmt->bindValue($index + 1, $table, PDO::PARAM_STR);
    }
    $stmt->execute();
}
