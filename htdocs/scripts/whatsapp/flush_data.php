<?php

declare(strict_types=1);

use App\Database\Connection;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Support/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

$tables = [
    'whatsapp_messages_archive',
    'whatsapp_messages',
    'whatsapp_threads',
    'whatsapp_user_permissions',
    'whatsapp_broadcasts',
    'whatsapp_contacts',
];

$pdo = Connection::instance('whatsapp');
$driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

function disableForeignKeys(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = OFF');
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }
}

function enableForeignKeys(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

$stats = [];

disableForeignKeys($pdo, $driver);

foreach ($tables as $table) {
    try {
        $countStmt = $pdo->query('SELECT COUNT(*) AS total FROM ' . $table);
        $countRow = $countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : null;
        $before = $countRow !== false && $countRow !== null ? (int)($countRow['total'] ?? 0) : null;

        $pdo->exec('DELETE FROM ' . $table);
        if ($driver === 'sqlite') {
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name = '" . $table . "'");
        }

        $stats[] = ['table' => $table, 'before' => $before];
    } catch (Throwable $exception) {
        $stats[] = ['table' => $table, 'error' => $exception->getMessage()];
    }
}

enableForeignKeys($pdo, $driver);

foreach ($stats as $row) {
    if (isset($row['error'])) {
        echo sprintf("%s: erro: %s\n", $row['table'], $row['error']);
        continue;
    }
    $before = $row['before'];
    $label = $before === null ? 'n/d' : (string)$before;
    echo sprintf("%s: removidos %s registros\n", $row['table'], $label);
}

echo "Concluido. Linhas/configurações foram mantidas.\n";
