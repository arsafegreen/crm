<?php

declare(strict_types=1);

use App\Repositories\CampaignMessageRepository;
use App\Repositories\EmailAccountRepository;
use App\Repositories\Marketing\MailQueueRepository;
use App\Repositories\Marketing\MailDeliveryLogRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use App\Services\Mail\MimeMessageBuilder;
use App\Services\Mail\SmtpMailer;
use App\Services\Marketing\DeliveryGuard;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = parseOptions($argv);
$workerId = $options['worker'] ?? ('mailer-' . bin2hex(random_bytes(3)));
$batchSize = (int)($options['batch'] ?? 10);
$batchSize = max(1, $batchSize);
$sleepSeconds = max(1, (int)($options['sleep'] ?? 5));
$runOnce = (bool)($options['once'] ?? false);
$accountId = isset($options['account_id']) ? (int)$options['account_id'] : null;
$logFile = resolveLogPath($options['log'] ?? null);
ensureLogDirectory($logFile);

$mailQueue = new MailQueueRepository();
$messageRepository = new CampaignMessageRepository();
$emailAccounts = new EmailAccountRepository();
$deliveryLog = new MailDeliveryLogRepository();
$contactRepo = new MarketingContactRepository();
$guard = new DeliveryGuard();

$accountRow = $emailAccounts->findActiveSender($accountId);
if ($accountRow === null) {
    logMessage('Nenhuma conta de envio ativa encontrada.', $logFile, true);
    exit(1);
}

$account = hydrateAccount($accountRow);
$mailer = new SmtpMailer([
    'host' => $account['smtp_host'],
    'port' => $account['smtp_port'],
    'encryption' => $account['encryption'],
    'auth_mode' => $account['auth_mode'],
    'username' => $account['credentials']['username'] ?? null,
    'password' => $account['credentials']['password'] ?? null,
]);

logMessage(
    sprintf(
        'Worker %s iniciado usando conta #%d (%s)',
        $workerId,
        (int)$account['id'],
        $account['from_email']
    ),
    $logFile
);

$cycle = 0;
do {
    $jobs = $mailQueue->claimPending($workerId, $batchSize);

    if ($jobs === []) {
        if ($runOnce) {
            break;
        }
        sleep($sleepSeconds);
        continue;
    }

    $cycle++;
    $sentThisCycle = 0;
    $failedThisCycle = 0;

    foreach ($jobs as $job) {
        $result = processJob($job, $account, $mailer, $mailQueue, $messageRepository, $deliveryLog, $contactRepo, $guard, $logFile);
        if ($result) {
            $sentThisCycle++;
        } else {
            $failedThisCycle++;
        }
    }

    logMessage(
        sprintf(
            '[CICLO #%d] Processados %d jobs (ok=%d, erros=%d)',
            $cycle,
            count($jobs),
            $sentThisCycle,
            $failedThisCycle
        ),
        $logFile
    );

    if ($runOnce) {
        break;
    }
} while (true);

function processJob(
    array $job,
    array $account,
    SmtpMailer $mailer,
    MailQueueRepository $queue,
    CampaignMessageRepository $messages,
    MailDeliveryLogRepository $deliveryLog,
    MarketingContactRepository $contactRepo,
    DeliveryGuard $guard,
    string $logFile
): bool
{
    $jobId = (int)$job['id'];
    $campaignMessageId = isset($job['campaign_message_id']) ? (int)$job['campaign_message_id'] : 0;

    try {
        $payload = decodeJson($job['payload'] ?? null);
        $contactId = isset($job['contact_id']) ? (int)$job['contact_id'] : null;
        $recipientEmail = sanitizeEmail((string)$job['recipient_email']);
        $recipientName = trim((string)($job['recipient_name'] ?? ''));
        $subject = trim((string)($job['subject'] ?? ''));
        $bodyHtml = $job['body_html'] ?? null;
        $bodyText = $job['body_text'] ?? null;

        if ($recipientEmail === null) {
            throw new RuntimeException('Destinatário inválido.');
        }

        $precheck = $guard->precheck($recipientEmail);
        $contactId = $contactId ?: ($precheck['contact_id'] ?? null);
        if ($precheck['deliverable'] === false) {
            $reason = $precheck['reason'] ?? 'Contato bloqueado para envio.';
            if ($contactId) {
                // Suprime para evitar reenvios futuros sem afetar throughput.
                $contactRepo->incrementBounce($contactId, false);
                $contactRepo->markOptOut($contactId, 'precheck_block');
            }
            $queue->markFailed($jobId, $reason, null);
            if ($campaignMessageId > 0) {
                $messages->markFailed($campaignMessageId, $reason);
            }
            $queue->logEvent($jobId, $precheck['classification'], [
                'occurred_at' => now(),
                'recipient' => $recipientEmail,
                'reason' => $reason,
                'stage' => 'precheck',
                'contact_id' => $contactId,
            ]);
            $deliveryLog->record($jobId, $precheck['classification'], [
                'occurred_at' => now(),
                'recipient' => $recipientEmail,
                'reason' => $reason,
                'stage' => 'precheck',
            ], $contactId);
            logMessage(sprintf('[SKIP] Job #%d suprimido: %s', $jobId, $reason), $logFile, true);
            return false;
        }

        if ($subject === '') {
            throw new RuntimeException('Assunto não definido.');
        }

        if ($bodyHtml === null && $bodyText === null) {
            throw new RuntimeException('Conteúdo do job não encontrado.');
        }

        $fromEmail = sanitizeEmail($account['from_email']);
        if ($fromEmail === null) {
            throw new RuntimeException('Conta de envio sem e-mail válido.');
        }

        $headers = $account['headers'];
        if (isset($payload['headers']) && is_array($payload['headers'])) {
            $headers = array_merge($headers, $payload['headers']);
        }

        if ($campaignMessageId > 0) {
            $messages->markProcessing($campaignMessageId);
        }

        $rawMessage = MimeMessageBuilder::build([
            'from_email' => $fromEmail,
            'from_name' => $account['from_name'],
            'to_email' => $recipientEmail,
            'to_name' => $recipientName,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'reply_to' => $account['reply_to'],
            'headers' => $headers,
        ]);

        $mailer->send([
            'from' => $fromEmail,
            'to' => $recipientEmail,
            'data' => $rawMessage,
        ]);

        $queue->markSent($jobId);
        if ($campaignMessageId > 0) {
            $messages->markSent($campaignMessageId);
        }
        $queue->logEvent($jobId, 'sent', [
            'occurred_at' => now(),
            'recipient' => $recipientEmail,
            'contact_id' => $contactId,
        ]);
        $deliveryLog->record($jobId, 'sent', [
            'occurred_at' => now(),
            'recipient' => $recipientEmail,
        ], $contactId);

        logMessage(sprintf('[OK] Job #%d enviado para %s', $jobId, $recipientEmail), $logFile);
        return true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $classification = $guard->classifyError($error);
        $queue->markFailed($jobId, $error);
        if ($contactId) {
            $isHard = $classification === 'hard_bounce';
            $contactRepo->incrementBounce($contactId, false);
            if ($isHard) {
                $contactRepo->markOptOut($contactId, 'hard_bounce');
            }
        }
        if ($campaignMessageId > 0) {
            $messages->markFailed($campaignMessageId, $error);
        }
        $queue->logEvent($jobId, $classification, [
            'occurred_at' => now(),
            'error' => $error,
        ]);
        $deliveryLog->record($jobId, $classification, [
            'occurred_at' => now(),
            'error' => $error,
        ], $contactId);
        logMessage(sprintf('[ERRO] Job #%d falhou: %s', $jobId, $error), $logFile, true);
        return false;
    }
}

/**
 * @return array<string, mixed>
 */
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

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function hydrateAccount(array $row): array
{
    $row['credentials'] = decodeJson($row['credentials'] ?? null);
    $row['headers'] = decodeJson($row['headers'] ?? null);
    $row['settings'] = decodeJson($row['settings'] ?? null);
    $row['headers'] = is_array($row['headers']) ? $row['headers'] : [];
    $row['smtp_port'] = (int)($row['smtp_port'] ?? 587);
    $row['encryption'] = strtolower((string)($row['encryption'] ?? 'tls'));
    $row['auth_mode'] = strtolower((string)($row['auth_mode'] ?? 'login'));
    $row['from_email'] = trim((string)$row['from_email']);
    $row['from_name'] = trim((string)($row['from_name'] ?? ''));
    $row['reply_to'] = trim((string)($row['reply_to'] ?? '')) ?: null;

    return $row;
}

/**
 * @return array<string, mixed>
 */
function decodeJson(?string $payload): array
{
    if ($payload === null || trim($payload) === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
}

function sanitizeEmail(string $value): ?string
{
    $email = strtolower(trim($value));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    return $email;
}

function resolveLogPath(?string $path): string
{
    if ($path === null || trim($path) === '') {
        return storage_path('logs' . DIRECTORY_SEPARATOR . 'mail_worker.log');
    }

    $normalized = trim($path);
    if (
        preg_match('/^[A-Za-z]:\\/', $normalized) === 1
        || str_starts_with($normalized, '/')
        || str_starts_with($normalized, '\\')
    ) {
        return $normalized;
    }

    return base_path($normalized);
}

function ensureLogDirectory(string $filePath): void
{
    $directory = dirname($filePath);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

function logMessage(string $message, string $logFile, bool $isError = false): void
{
    $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), $message);
    $stream = $isError ? STDERR : STDOUT;
    fwrite($stream, $line . PHP_EOL);

    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}
