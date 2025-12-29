<?php

declare(strict_types=1);

use App\Services\Email\EmailHealthService;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = parseOptions($argv);
$service = new EmailHealthService();
$summaries = $service->summarize();
$alerts = $service->detectAlerts([
    'imap_lag_threshold' => $options['imap_lag_threshold'] ?? null,
    'limit_threshold' => $options['limit_threshold'] ?? null,
    'batch_threshold' => $options['batch_threshold'] ?? null,
    'jobs_threshold' => $options['jobs_threshold'] ?? null,
]);

printSummary($summaries, (bool)($options['quiet'] ?? false));
printAlerts($alerts);

function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        if (count($parts) === 2) {
            $options[str_replace('-', '_', $parts[0])] = is_numeric($parts[1])
                ? (float)$parts[1]
                : $parts[1];
        } elseif ($arg === '--quiet') {
            $options['quiet'] = true;
        }
    }

    return $options;
}

function printSummary(array $summaries, bool $quiet): void
{
    if ($quiet) {
        return;
    }

    if ($summaries === []) {
        fwrite(STDOUT, "Nenhuma conta configurada.\n");
        return;
    }

    foreach ($summaries as $summary) {
        fwrite(STDOUT, sprintf("Conta #%d - %s (%s)\n", $summary['account_id'], $summary['name'], $summary['status']));
        fwrite(STDOUT, sprintf(
            "  IMAP: %s | Último sync: %s | Lag: %s min\n",
            $summary['imap']['enabled'] ? 'ATIVO' : 'DESATIVADO',
            $summary['imap']['last_sync_at'] ? date('Y-m-d H:i:s', $summary['imap']['last_sync_at']) : 'nunca',
            $summary['imap']['lag_minutes'] ?? '-'
        ));
        fwrite(STDOUT, sprintf(
            "  Limites: %d/%d hora | %d/%d dia\n",
            $summary['rate_limit']['hourly']['usage'],
            $summary['rate_limit']['hourly']['limit'],
            $summary['rate_limit']['daily']['usage'],
            $summary['rate_limit']['daily']['limit']
        ));
        fwrite(STDOUT, sprintf(
            "  Fila: %d batches | %d sends | %d jobs\n",
            $summary['queues']['pending_batches'],
            $summary['queues']['pending_sends'],
            $summary['queues']['pending_jobs']
        ));
        fwrite(STDOUT, str_repeat('-', 40) . "\n");
    }
}

function printAlerts(array $alerts): void
{
    if ($alerts === []) {
        fwrite(STDOUT, "Nenhum alerta ativo.\n");
        return;
    }

    fwrite(STDOUT, "Alertas:\n");
    foreach ($alerts as $alert) {
        fwrite(STDOUT, sprintf(
            "- Conta #%d [%s]: %s\n",
            $alert['account_id'],
            strtoupper((string)$alert['type']),
            $alert['message']
        ));
    }
}
