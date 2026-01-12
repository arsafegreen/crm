<?php

declare(strict_types=1);

use App\Database\Connection;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Support/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

$slug = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($slug === '') {
    fwrite(STDERR, "Uso: php scripts/whatsapp/cleanup_alt_threads.php <instance-slug>\n");
    fwrite(STDERR, "Ex:  php scripts/whatsapp/cleanup_alt_threads.php wpp03\n");
    exit(1);
}

$pdo = Connection::instance('whatsapp');
$driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

$pattern = 'alt:' . $slug . ':%';

if ($driver === 'sqlite') {
    $pdo->exec('PRAGMA foreign_keys = OFF');
} else {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
}

$stmt = $pdo->prepare('SELECT id, contact_id, channel_thread_id FROM whatsapp_threads WHERE channel_thread_id LIKE :pattern');
$stmt->bindValue(':pattern', $pattern, PDO::PARAM_STR);
$stmt->execute();
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($threads === []) {
    echo "Nenhum thread encontrado para {$pattern}\n";
    exit(0);
}

$threadIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $threads)));
$contactIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['contact_id'], $threads)));

$placeholders = implode(',', array_fill(0, count($threadIds), '?'));

$deletedArchive = 0;
$deletedMessages = 0;
$deletedThreads = 0;
$deletedContacts = 0;

$pdo->beginTransaction();
try {
    $delArchive = $pdo->prepare("DELETE FROM whatsapp_messages_archive WHERE thread_id IN ($placeholders)");
    $delArchive->execute($threadIds);
    $deletedArchive = $delArchive->rowCount();

    $delMessages = $pdo->prepare("DELETE FROM whatsapp_messages WHERE thread_id IN ($placeholders)");
    $delMessages->execute($threadIds);
    $deletedMessages = $delMessages->rowCount();

    $delThreads = $pdo->prepare("DELETE FROM whatsapp_threads WHERE id IN ($placeholders)");
    $delThreads->execute($threadIds);
    $deletedThreads = $delThreads->rowCount();

    if ($contactIds !== []) {
        $contactPlaceholders = implode(',', array_fill(0, count($contactIds), '?'));
        $delContacts = $pdo->prepare(
            "DELETE FROM whatsapp_contacts
              WHERE id IN ($contactPlaceholders)
                AND id NOT IN (SELECT contact_id FROM whatsapp_threads)"
        );
        $delContacts->execute($contactIds);
        $deletedContacts = $delContacts->rowCount();
    }

    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
} finally {
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

echo "Cleanup {$slug} concluido.\n";
echo "Threads removidos: {$deletedThreads}\n";
echo "Mensagens removidas: {$deletedMessages}\n";
echo "Mensagens_archive removidas: {$deletedArchive}\n";
echo "Contatos removidos: {$deletedContacts}\n";

