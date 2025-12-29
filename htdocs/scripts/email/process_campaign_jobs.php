<?php

declare(strict_types=1);

use App\Repositories\Email\EmailJobRepository;
use App\Services\Email\CampaignDispatchService;
use RuntimeException;
use Throwable;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = parseOptions($argv);
$workerId = $options['worker'] ?? ('campaign-dispatch-' . bin2hex(random_bytes(3)));
$limit = max(1, (int)($options['limit'] ?? 200));
$sleepSeconds = max(1, (int)($options['sleep'] ?? 5));
$backoffSeconds = max(5, (int)($options['backoff'] ?? 30));
$runOnce = (bool)($options['once'] ?? false);

$jobs = new EmailJobRepository();
$dispatcher = new CampaignDispatchService();

logLine(sprintf('Worker %s aguardando jobs de campanha...', $workerId));

while (true) {
    $job = $jobs->reserveNext('email.campaign.dispatch', $workerId);

    if ($job === null) {
        if ($runOnce) {
            break;
        }
        sleep($sleepSeconds);
        continue;
    }

    $jobId = (int)$job['id'];
    try {
        $payload = decodePayload($job['payload'] ?? null);
        $batchId = (int)($payload['batch_id'] ?? 0);
        if ($batchId <= 0) {
            throw new RuntimeException('Job sem batch_id.');
        }

        $result = $dispatcher->dispatchBatch($batchId, ['limit' => $limit]);
        $message = sprintf(
            'Batch #%d processado (sent=%d, failed=%d, remaining=%d)',
            $batchId,
            $result['sent'],
            $result['failed'],
            $result['remaining']
        );

        if ($result['remaining'] > 0) {
            $jobs->release($jobId, ['available_at' => time() + $backoffSeconds]);
            logLine($message . ' -> reprogramado');
        } else {
            $jobs->markCompleted($jobId);
            logLine($message . ' -> concluído');
        }
    } catch (Throwable $exception) {
        $jobs->markFailed($jobId, $exception->getMessage());
        logLine(sprintf('Job #%d falhou: %s', $jobId, $exception->getMessage()), true);
    }

    if ($runOnce) {
        break;
    }
}

function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        if ($arg === '--once') {
            $options['once'] = true;
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        if (count($parts) === 2) {
            $options[str_replace('-', '_', $parts[0])] = $parts[1];
        }
    }

    return $options;
}

function decodePayload(?string $payload): array
{
    if ($payload === null || trim($payload) === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
}

function logLine(string $message, bool $isError = false): void
{
    $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), $message);
    $stream = $isError ? STDERR : STDOUT;
    fwrite($stream, $line . PHP_EOL);
}
