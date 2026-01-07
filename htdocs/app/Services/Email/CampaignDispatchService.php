<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Repositories\Email\EmailAccountRateLimitRepository;
use App\Repositories\Email\EmailCampaignBatchRepository;
use App\Repositories\Email\EmailCampaignRepository;
use App\Repositories\Email\EmailEventRepository;
use App\Repositories\Email\EmailSendRepository;
use App\Repositories\EmailAccountRepository;
use App\Services\Mail\MimeMessageBuilder;
use App\Services\Mail\SmtpMailer;
use App\Services\AlertService;
use RuntimeException;
use Throwable;

final class CampaignDispatchService
{
    private EmailCampaignRepository $campaigns;
    private EmailCampaignBatchRepository $batches;
    private EmailSendRepository $sends;
    private EmailEventRepository $events;
    private EmailAccountRepository $accounts;
    private EmailAccountRateLimitRepository $rateLimits;

    public function __construct(
        ?EmailCampaignRepository $campaigns = null,
        ?EmailCampaignBatchRepository $batches = null,
        ?EmailSendRepository $sends = null,
        ?EmailEventRepository $events = null,
        ?EmailAccountRepository $accounts = null,
        ?EmailAccountRateLimitRepository $rateLimits = null
    ) {
        $this->campaigns = $campaigns ?? new EmailCampaignRepository();
        $this->batches = $batches ?? new EmailCampaignBatchRepository();
        $this->sends = $sends ?? new EmailSendRepository();
        $this->events = $events ?? new EmailEventRepository();
        $this->accounts = $accounts ?? new EmailAccountRepository();
        $this->rateLimits = $rateLimits ?? new EmailAccountRateLimitRepository();
    }

    /**
     * @param array{limit?: int, max_attempts?: int} $options
     * @return array{batch_id: int, sent: int, failed: int, remaining: int, status: string}
     */
    public function dispatchBatch(int $batchId, array $options = []): array
    {
        $batch = $this->batches->find($batchId);
        if ($batch === null) {
            AlertService::push('email.dispatch', 'Lote não encontrado', ['batch_id' => $batchId]);
            throw new RuntimeException('Lote não encontrado.');
        }

        $campaign = $this->campaigns->find((int)$batch['email_campaign_id']);
        if ($campaign === null) {
            AlertService::push('email.dispatch', 'Campanha ausente para lote', ['batch_id' => $batchId]);
            throw new RuntimeException('Campanha ausente para o lote informado.');
        }

        if ((string)$batch['status'] === 'completed') {
            return [
                'batch_id' => $batchId,
                'sent' => 0,
                'failed' => 0,
                'remaining' => 0,
                'status' => 'completed',
            ];
        }

        if ((string)$batch['status'] === 'pending') {
            $this->batches->markProcessing($batchId);
            $batch['status'] = 'processing';
        }

        if (($campaign['status'] ?? '') === 'scheduled') {
            $this->campaigns->update((int)$campaign['id'], ['status' => 'sending']);
            $campaign['status'] = 'sending';
        }

        $account = $this->resolveAccount($campaign['from_account_id'] ?? null);
        $message = $this->resolveMessageTemplate($campaign);
        $mailer = $this->buildMailer($account);
        $limit = max(1, (int)($options['limit'] ?? 200));
        $maxAttempts = max(1, (int)($options['max_attempts'] ?? 3));

        $pending = $this->sends->listByBatch($batchId, [
            'statuses' => ['pending', 'retry'],
            'scheduled_before' => time(),
        ], $limit);

        if ($pending === []) {
            $remaining = $this->sends->countByBatch($batchId, ['statuses' => ['pending', 'retry']]);
            if ($remaining === 0) {
                $this->closeBatchAndMaybeCampaign($batchId, (int)$campaign['id']);
                return [
                    'batch_id' => $batchId,
                    'sent' => 0,
                    'failed' => 0,
                    'remaining' => 0,
                    'status' => 'completed',
                ];
            }

            return [
                'batch_id' => $batchId,
                'sent' => 0,
                'failed' => 0,
                'remaining' => $remaining,
                'status' => (string)$batch['status'],
            ];
        }

        $rateState = $this->refreshRateState((int)$account['id']);
        $budget = $this->computeBudget($account, $rateState, $limit);
        if ($budget === 0) {
            AlertService::push('email.dispatch', 'Limite de envio atingido (aguardando pr¢xima janela)', [
                'campaign_id' => (int)$campaign['id'],
                'batch_id' => $batchId,
                'account_id' => (int)$account['id'],
            ]);

            $remaining = $this->sends->countByBatch($batchId, ['statuses' => ['pending', 'retry']]);

            return [
                'batch_id' => $batchId,
                'sent' => 0,
                'failed' => 0,
                'remaining' => $remaining,
                'status' => 'throttled',
            ];
        }

        if (count($pending) > $budget) {
            $pending = array_slice($pending, 0, $budget);
        }

        $sent = 0;
        $failed = 0;

        foreach ($pending as $send) {
            $recipient = $this->sanitizeEmail($send['target_email'] ?? null);
            $sendId = (int)$send['id'];

            if ($recipient === null) {
                $failed++;
                $attempts = ((int)$send['attempts']) + 1;
                $this->sends->updateStatus($sendId, 'failed', [
                    'attempts' => $attempts,
                    'last_error' => 'Destinatário inválido.',
                ]);
                $this->logEvent($sendId, 'error', ['reason' => 'invalid_recipient']);
                AlertService::push('email.dispatch', 'Destinatário inválido', [
                    'send_id' => $sendId,
                    'batch_id' => $batchId,
                    'campaign_id' => (int)$campaign['id'],
                ]);
                continue;
            }

            $context = [
                'from_email' => $account['from_email'],
                'from_name' => $account['from_name'],
                'to_email' => $recipient,
                'to_name' => trim((string)($send['target_name'] ?? '')) ?: $recipient,
                'subject' => $message['subject'],
                'body_html' => $message['body_html'],
                'body_text' => $message['body_text'],
                'reply_to' => $account['reply_to'],
                'headers' => array_merge($account['headers'], $message['headers']),
            ];

            try {
                $rawMessage = MimeMessageBuilder::build($context);
                $mailer->send([
                    'from' => $account['from_email'],
                    'to' => $recipient,
                    'data' => $rawMessage,
                ]);

                $sent++;
                $this->sends->updateStatus($sendId, 'sent', [
                    'attempts' => ((int)$send['attempts']) + 1,
                    'sent_at' => time(),
                    'last_error' => null,
                ]);
                $this->logEvent($sendId, 'sent', ['recipient' => $recipient]);
            } catch (Throwable $exception) {
                $failed++;
                $attempts = ((int)$send['attempts']) + 1;
                $status = $attempts >= $maxAttempts ? 'failed' : 'retry';

                $this->sends->updateStatus($sendId, $status, [
                    'attempts' => $attempts,
                    'last_error' => $exception->getMessage(),
                ]);
                $this->logEvent($sendId, 'error', [
                    'message' => $exception->getMessage(),
                ]);
                AlertService::push('email.dispatch', 'Falha ao enviar', [
                    'send_id' => $sendId,
                    'batch_id' => $batchId,
                    'campaign_id' => (int)$campaign['id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($sent > 0) {
            $this->rateLimits->increment((int)$account['id'], $sent, $sent);
        }

        if (($sent + $failed) > 0) {
            $this->batches->incrementCounters($batchId, [
                'processed_count' => $sent + $failed,
                'failed_count' => $failed,
            ]);
        }

        $remaining = $this->sends->countByBatch($batchId, ['statuses' => ['pending', 'retry']]);
        if ($remaining === 0) {
            $this->closeBatchAndMaybeCampaign($batchId, (int)$campaign['id']);
            $status = 'completed';
        } else {
            $status = (string)$batch['status'];
        }

        return [
            'batch_id' => $batchId,
            'sent' => $sent,
            'failed' => $failed,
            'remaining' => $remaining,
            'status' => $status,
        ];
    }

    private function resolveAccount(?int $accountId): array
    {
        $row = null;
        if ($accountId !== null) {
            $row = $this->accounts->find($accountId);
        }

        if ($row === null) {
            $row = $this->accounts->findActiveSender();
        }

        if ($row === null) {
            throw new RuntimeException('Nenhuma conta de envio ativa disponível.');
        }

        return $this->hydrateAccount($row);
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array{subject: string, body_html: ?string, body_text: ?string, headers: array}
     */
    private function resolveMessageTemplate(array $campaign): array
    {
        $settings = $this->decodeJson($campaign['settings'] ?? null);
        $subject = trim((string)($campaign['subject'] ?? ($settings['subject'] ?? '')));
        if ($subject === '') {
            throw new RuntimeException('Campanha sem assunto configurado.');
        }

        $bodyHtml = isset($settings['body_html']) ? (string)$settings['body_html'] : null;
        $bodyText = isset($settings['body_text']) ? (string)$settings['body_text'] : null;

        if ($bodyHtml === null && $bodyText === null) {
            throw new RuntimeException('Campanha sem conteúdo para envio.');
        }

        $headers = [];
        if (isset($settings['headers']) && is_array($settings['headers'])) {
            foreach ($settings['headers'] as $key => $value) {
                $keyName = trim((string)$key);
                if ($keyName === '') {
                    continue;
                }
                $headers[$keyName] = (string)$value;
            }
        }

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'headers' => $headers,
        ];
    }

    private function buildMailer(array $account): SmtpMailer
    {
        return new SmtpMailer([
            'host' => $account['smtp_host'],
            'port' => $account['smtp_port'],
            'encryption' => $account['encryption'],
            'auth_mode' => $account['auth_mode'],
            'username' => $account['credentials']['username'] ?? null,
            'password' => $account['credentials']['password'] ?? null,
        ]);
    }

    private function hydrateAccount(array $row): array
    {
        $row['credentials'] = $this->decodeJson($row['credentials'] ?? null);
        $row['headers'] = $this->decodeJson($row['headers'] ?? null);
        $row['settings'] = $this->decodeJson($row['settings'] ?? null);
        $row['headers'] = is_array($row['headers']) ? $row['headers'] : [];
        $row['smtp_port'] = (int)($row['smtp_port'] ?? 587);
        $row['encryption'] = strtolower((string)($row['encryption'] ?? 'tls'));
        $row['auth_mode'] = strtolower((string)($row['auth_mode'] ?? 'login'));
        $fromEmail = $this->sanitizeEmail($row['from_email'] ?? null);
        if ($fromEmail === null) {
            throw new RuntimeException('Conta de envio sem remetente válido.');
        }
        $row['from_email'] = $fromEmail;
        $row['from_name'] = trim((string)($row['from_name'] ?? ''));
        $row['reply_to'] = $this->sanitizeEmail($row['reply_to'] ?? null);

        return $row;
    }

    private function refreshRateState(int $accountId): array
    {
        $state = $this->rateLimits->find($accountId);
        if ($state === null) {
            return $this->bootstrapRateLimit($accountId);
        }

        $now = time();
        $windowStart = (int)$state['window_start'];
        if ($now - $windowStart >= 3600) {
            $this->rateLimits->resetWindow($accountId);
            $state = $this->rateLimits->find($accountId);
        }

        if ($state === null) {
            return $this->bootstrapRateLimit($accountId);
        }

        $lastReset = (int)($state['last_reset_at'] ?? 0);
        if ($lastReset === 0 || ($now - $lastReset) >= 86400) {
            $this->rateLimits->upsert($accountId, [
                'daily_sent' => 0,
                'last_reset_at' => $now,
            ]);
            $state = $this->rateLimits->find($accountId) ?? $this->bootstrapRateLimit($accountId);
        }

        return $state;
    }

    private function bootstrapRateLimit(int $accountId): array
    {
        $now = time();
        $this->rateLimits->upsert($accountId, [
            'window_start' => $now,
            'hourly_sent' => 0,
            'daily_sent' => 0,
            'last_reset_at' => $now,
            'metadata' => null,
        ]);

        return $this->rateLimits->find($accountId) ?? [
            'window_start' => $now,
            'hourly_sent' => 0,
            'daily_sent' => 0,
            'last_reset_at' => $now,
        ];
    }

    private function computeBudget(array $account, array $state, int $requested): int
    {
        $remaining = $requested;
        $hourlyLimit = max(0, (int)($account['hourly_limit'] ?? 0));
        $dailyLimit = max(0, (int)($account['daily_limit'] ?? 0));
        $burstLimit = max(0, (int)($account['burst_limit'] ?? 0));

        if ($burstLimit > 0) {
            $remaining = min($remaining, $burstLimit);
        }

        if ($hourlyLimit > 0) {
            $remaining = min($remaining, max(0, $hourlyLimit - (int)$state['hourly_sent']));
        }

        if ($dailyLimit > 0) {
            $remaining = min($remaining, max(0, $dailyLimit - (int)$state['daily_sent']));
        }

        return max(0, $remaining);
    }

    private function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $value = strtolower(trim($email));
        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function closeBatchAndMaybeCampaign(int $batchId, int $campaignId): void
    {
        $this->batches->markCompleted($batchId);
        $remainingBatches = $this->batches->listByCampaign($campaignId);
        $open = array_filter($remainingBatches, static function (array $batch): bool {
            return ($batch['status'] ?? '') !== 'completed';
        });

        if ($open === []) {
            $this->campaigns->update($campaignId, ['status' => 'completed']);
        }
    }

    private function logEvent(int $sendId, string $type, array $payload = []): void
    {
        $this->events->insert([
            'send_id' => $sendId,
            'event_type' => $type,
            'occurred_at' => time(),
            'payload' => $this->encodeJson($payload),
        ]);
    }

    private function decodeJson(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
