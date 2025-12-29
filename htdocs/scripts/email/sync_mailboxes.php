<?php

declare(strict_types=1);

use App\Services\AlertService;
use App\Services\Email\MailboxSyncService;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

$options = parseOptions($argv);
$service = new MailboxSyncService();

try {
    if (isset($options['account_id'])) {
        $folders = resolveFoldersOption($options);
        $result = $service->syncAccount((int)$options['account_id'], [
            'limit' => isset($options['limit']) ? (int)$options['limit'] : 100,
            'folders' => $folders,
            'force_resync' => !empty($options['force_resync']),
            'lookback_days' => isset($options['lookback_days']) ? (int)$options['lookback_days'] : 365,
        ]);
        outputStats([$result]);
    } else {
        $folders = resolveFoldersOption($options);
        $results = $service->syncAll([
            'limit' => isset($options['limit']) ? (int)$options['limit'] : 100,
            'folders' => $folders,
            'force_resync' => !empty($options['force_resync']),
            'lookback_days' => isset($options['lookback_days']) ? (int)$options['lookback_days'] : 365,
        ]);
        outputStats($results);
    }
} catch (RuntimeException $exception) {
    AlertService::push('email.sync', 'Erro ao sincronizar via CLI', [
        'context' => 'cli',
        'error' => $exception->getMessage(),
        'options' => $options,
    ]);
    fwrite(STDERR, '[ERRO] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (\Throwable $exception) {
    AlertService::push('email.sync', 'Erro inesperado ao sincronizar via CLI', [
        'context' => 'cli',
        'error' => $exception->getMessage(),
        'exception' => get_class($exception),
        'options' => $options,
    ]);
    fwrite(STDERR, '[FATAL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

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

/**
 * @param array<string, string> $options
 * @return array<int, string>|null
 */
function resolveFoldersOption(array $options): ?array
{
    $raw = null;
    if (isset($options['folders64'])) {
        $decoded = base64_decode((string)$options['folders64'], true);
        if ($decoded !== false && $decoded !== '') {
            $raw = $decoded;
        }
    } elseif (isset($options['folders'])) {
        $raw = (string)$options['folders'];
    }

    if ($raw === null) {
        return null;
    }

    $split = array_map(static fn(string $value): string => trim($value), explode(',', $raw));
    $filtered = array_values(array_filter($split, static fn(string $value): bool => $value !== ''));

    return $filtered === [] ? null : $filtered;
}

/**
 * @param array<int, array<string, mixed>> $stats
 */
function outputStats(array $stats): void
{
    if ($stats === []) {
        fwrite(STDOUT, "Nenhuma conta sincronizada.\n");
        return;
    }

    foreach ($stats as $entry) {
        if ($entry === null) {
            continue;
        }

        fwrite(STDOUT, sprintf("Conta #%d\n", $entry['account_id'] ?? 0));
        fwrite(STDOUT, sprintf("- Pastas sincronizadas: %d\n", count($entry['folders'] ?? [])));
        fwrite(STDOUT, sprintf("- Mensagens novas: %d\n", $entry['fetched'] ?? 0));
        fwrite(STDOUT, sprintf("- Mensagens ignoradas: %d\n", $entry['skipped'] ?? 0));
        fwrite(STDOUT, sprintf("- Erros: %d\n", $entry['errors'] ?? 0));
        fwrite(STDOUT, str_repeat('-', 30) . "\n");
    }
}
