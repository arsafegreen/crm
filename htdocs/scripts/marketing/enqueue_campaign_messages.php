<?php

declare(strict_types=1);

use App\Repositories\CampaignMessageRepository;
use App\Repositories\Marketing\MailQueueRepository;
use App\Repositories\TemplateRepository;
use App\Repositories\Marketing\MailDeliveryLogRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use App\Services\Marketing\DeliveryGuard;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$limit = parseLimit($argv) ?? 200;
$limit = max(1, $limit);

$messagesRepo = new CampaignMessageRepository();
$mailQueue = new MailQueueRepository();
$templateRepository = new TemplateRepository();
$deliveryLog = new MailDeliveryLogRepository();
$contactRepo = new MarketingContactRepository();
$guard = new DeliveryGuard();

$messages = $messagesRepo->pending($limit);
if ($messages === []) {
    echo "Nenhuma mensagem pendente para enfileirar." . PHP_EOL;
    exit(0);
}

echo sprintf("Processando %d mensagens pendentes...", count($messages)) . PHP_EOL;

foreach ($messages as $message) {
    $messageId = (int)($message['id'] ?? 0);

    try {
        $payload = decodePayload($message['payload'] ?? null);
        $recipientEmail = resolveRecipient($payload);
        $recipientName = trim((string)($payload['to_name'] ?? '')) ?: null;

        [$subject, $bodyHtml, $bodyText] = resolveContent($message, $payload, $templateRepository);

        $precheck = $guard->precheck($recipientEmail);
        if ($precheck['deliverable'] === false) {
            $reason = $precheck['reason'] ?? 'Contato bloqueado para envio.';
            if (!empty($precheck['contact_id'])) {
                $contactRepo->incrementBounce((int)$precheck['contact_id'], false);
                $contactRepo->markOptOut((int)$precheck['contact_id'], 'precheck_block');
            }
            $messagesRepo->markFailed($messageId, $reason);
            $deliveryLog->record(null, $precheck['classification'], [
                'occurred_at' => now(),
                'recipient' => $recipientEmail,
                'reason' => $reason,
                'stage' => 'precheck',
            ], $precheck['contact_id'] ?? null);
            echo sprintf('[SKIP] Mensagem #%d suprimida: %s', $messageId, $reason) . PHP_EOL;
            continue;
        }

        $templateVersionId = isset($message['template_version_id']) ? (int)$message['template_version_id'] : null;
        if ($templateVersionId === 0) {
            $templateVersionId = isset($payload['template_version_id']) ? (int)$payload['template_version_id'] : null;
        }

        $jobPayload = [
            'source' => 'campaign',
            'campaign_id' => (int)($message['campaign_id'] ?? 0),
            'campaign_message_id' => $messageId,
            'template_id' => $payload['template_id'] ?? null,
            'template_version_id' => $templateVersionId,
            'placeholders' => $payload['placeholders'] ?? [],
        ];

        $scheduledFor = normalizeTimestamp($message['scheduled_for'] ?? null);

        $jobId = $mailQueue->enqueue([
            'campaign_message_id' => $messageId,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'payload' => $jobPayload,
            'scheduled_at' => $scheduledFor,
            'available_at' => $scheduledFor,
            'contact_id' => $precheck['contact_id'] ?? null,
        ]);

        $messagesRepo->markQueued($messageId);
        echo sprintf("[OK] Mensagem #%d enfileirada como job #%d", $messageId, $jobId) . PHP_EOL;
    } catch (Throwable $exception) {
        $messagesRepo->markFailed($messageId, $exception->getMessage());
        echo sprintf("[ERRO] Mensagem #%d: %s", $messageId, $exception->getMessage()) . PHP_EOL;
    }
}

function parseLimit(array $argv): ?int
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $value = (int)substr($arg, 8);
            return $value > 0 ? $value : null;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function decodePayload(?string $payload): array
{
    if ($payload === null || trim($payload) === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
}

function resolveRecipient(array $payload): string
{
    $email = trim((string)($payload['to_email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Destinatário inválido ou ausente.');
    }

    return strtolower($email);
}

/**
 * @return array{0: string, 1: ?string, 2: ?string}
 */
function resolveContent(array $message, array $payload, TemplateRepository $templates): array
{
    $subject = trim((string)($payload['subject'] ?? ''));
    $bodyHtml = normalizeStringOrNull($payload['body_html'] ?? null);
    $bodyText = normalizeStringOrNull($payload['body_text'] ?? null);

    $templateVersionId = isset($message['template_version_id']) ? (int)$message['template_version_id'] : null;
    if ($templateVersionId === 0) {
        $templateVersionId = isset($payload['template_version_id']) ? (int)$payload['template_version_id'] : null;
    }

    if (($subject === '' || ($bodyHtml === null && $bodyText === null)) && $templateVersionId) {
        $version = $templates->findVersionById($templateVersionId);
        if ($version !== null) {
            if ($subject === '' && !empty($version['subject'])) {
                $subject = trim((string)$version['subject']);
            }

            if ($bodyHtml === null && !empty($version['body_html'])) {
                $bodyHtml = (string)$version['body_html'];
            }

            if ($bodyText === null && !empty($version['body_text'])) {
                $bodyText = (string)$version['body_text'];
            }
        }
    }

    if ($subject === '') {
        throw new RuntimeException('Assunto não encontrado para o disparo.');
    }

    if ($bodyHtml === null && $bodyText === null) {
        throw new RuntimeException('Conteúdo do template ausente.');
    }

    if ($bodyText === null && $bodyHtml !== null) {
        $bodyText = renderPlainText($bodyHtml);
    }

    return [$subject, $bodyHtml, $bodyText];
}

function normalizeStringOrNull(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string)$value);
    return $string === '' ? null : $string;
}

function renderPlainText(string $html): string
{
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text ?? '') ?? '';
    return trim($text);
}

function normalizeTimestamp(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_int($value)) {
        return $value;
    }

    $int = (int)$value;
    return $int > 0 ? $int : null;
}
