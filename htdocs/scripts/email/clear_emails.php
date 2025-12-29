<?php

declare(strict_types=1);

use App\Database\Connection;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = parseOptions($argv);
$pdo = Connection::instance();

$targetAccounts = [];
if (isset($options['account_id'])) {
    $targetAccounts[] = (int)$options['account_id'];
} else {
    // default: clear both contato (3) and safegreen (4) if present
    $targetAccounts = [3, 4];
}

foreach ($targetAccounts as $accountId) {
    if ($accountId <= 0) {
        continue;
    }

    echo "Limpando conta #{$accountId}\n";
    $pdo->beginTransaction();
    try {
        $msgIds = $pdo->prepare('SELECT id FROM email_messages WHERE account_id = :account_id');
        $msgIds->execute([':account_id' => $accountId]);
        $ids = array_map(fn($row) => (int)$row['id'], $msgIds->fetchAll(PDO::FETCH_ASSOC) ?: []);

        if ($ids !== []) {
            $in = implode(', ', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM email_message_participants WHERE message_id IN ({$in})")->execute($ids);
            $pdo->prepare("DELETE FROM email_attachments WHERE message_id IN ({$in})")->execute($ids);
        }

        $pdo->prepare('DELETE FROM email_messages WHERE account_id = :account_id')->execute([':account_id' => $accountId]);
        $pdo->prepare('DELETE FROM email_threads WHERE account_id = :account_id')->execute([':account_id' => $accountId]);
        $pdo->prepare('UPDATE email_folders SET sync_token = NULL, last_synced_at = NULL, unread_count = 0 WHERE account_id = :account_id')->execute([':account_id' => $accountId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Erro na conta {$accountId}: {$e->getMessage()}\n");
        continue;
    }

    // Limpa arquivos persistidos
    $base = storage_path('email');
    $paths = [
        $base . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . $accountId,
        $base . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $accountId,
    ];
    foreach ($paths as $path) {
        if (is_dir($path)) {
            rrmdir($path);
        }
    }

    echo "Conta #{$accountId} limpa.\n";
}

echo "Concluido.\n";

function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        if (count($parts) === 2) {
            $options[str_replace('-', '_', $parts[0])] = $parts[1];
        }
    }
    return $options;
}

function rrmdir(string $dir): void
{
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
