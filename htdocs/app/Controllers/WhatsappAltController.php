<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\SettingRepository;
use App\Services\AlertService;
use App\Services\Whatsapp\WhatsappService;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use function base_path;
use function bin2hex;
use function config;
use function hash;
use function random_bytes;
use function strlen;
use function str_starts_with;
use function substr;

final class WhatsappAltController
{
    private WhatsappService $whatsapp;
    private SettingRepository $settings;

    public function __construct(?WhatsappService $service = null, ?SettingRepository $settings = null)
    {
        $this->whatsapp = $service ?? new WhatsappService();
        $this->settings = $settings ?? new SettingRepository();
    }

    public function gatewayStatus(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->guardWhatsappAccess($user);
        try {
            $instance = $this->resolveGatewayInstance($request);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 404);
        }

        try {
            $payload = $this->callGateway($instance, 'GET', '/health');
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        $payload['slug'] = $instance['slug'];
        $payload['label'] = $instance['label'];

        return new JsonResponse(['gateway' => $payload]);
    }

    public function gatewayQr(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->guardWhatsappAccess($user);
        try {
            $instance = $this->resolveGatewayInstance($request);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 404);
        }

        try {
            $payload = $this->callGateway($instance, 'GET', '/qr');
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        $rawQr = (string)($payload['qr'] ?? '');
        $qrSource = $rawQr !== '' ? $this->normalizeQrPayload($rawQr, '') : null;
        $generatedAt = $this->coerceTimestamp($payload['generatedAt'] ?? $payload['generated_at'] ?? null);
        $expiresAt = $this->coerceTimestamp($payload['expiresAt'] ?? $payload['expires_at'] ?? null);

        if ($qrSource === null) {
            return new JsonResponse([
                'instance' => $instance['slug'],
                'qr' => null,
                'generated_at' => $generatedAt,
                'expires_at' => $expiresAt,
                'error' => 'qr_unavailable',
            ]);
        }

        return new JsonResponse([
            'instance' => $instance['slug'],
            'qr' => $qrSource,
            'generated_at' => $generatedAt,
            'expires_at' => $expiresAt,
        ]);
    }

    public function gatewayReset(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->guardWhatsappAccess($user);
        try {
            $instance = $this->resolveGatewayInstance($request);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 404);
        }

        try {
            $this->callGateway($instance, 'POST', '/reset-session');
        } catch (RuntimeException $exception) {
            AlertService::push('whatsapp.alt_reset', $exception->getMessage(), [
                'actor_id' => $user->id,
                'instance' => $instance['slug'],
            ]);
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        return new JsonResponse(['ok' => true, 'instance' => $instance['slug']]);
    }

    public function gatewayStart(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->guardWhatsappAccess($user);
        try {
            $instance = $this->resolveGatewayInstance($request);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 404);
        }

        try {
            $message = $this->launchGatewayProcess($instance);
        } catch (RuntimeException $exception) {
            AlertService::push('whatsapp.alt_start', $exception->getMessage(), [
                'actor_id' => $user->id,
                'instance' => $instance['slug'],
            ]);
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        return new JsonResponse(['ok' => true, 'message' => $message, 'instance' => $instance['slug']]);
    }

    public function gatewayStop(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->guardWhatsappAccess($user);
        try {
            $instance = $this->resolveGatewayInstance($request);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 404);
        }

        try {
            $this->callGateway($instance, 'POST', '/shutdown');
        } catch (RuntimeException $exception) {
            AlertService::push('whatsapp.alt_stop', $exception->getMessage(), [
                'actor_id' => $user->id,
                'instance' => $instance['slug'],
            ]);
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        return new JsonResponse([
            'ok' => true,
            'instance' => $instance['slug'],
            'message' => 'Gateway sendo encerrado... aguarde alguns segundos.',
        ]);
    }

    public function gatewayHistorySync(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->guardWhatsappAccess($user);
        try {
            $instance = $this->resolveGatewayInstance($request);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 404);
        }
        $payload = $this->buildHistorySyncPayload($request);

        try {
            $response = $this->callGateway($instance, 'POST', '/history-sync', $payload);
        } catch (RuntimeException $exception) {
            AlertService::push('whatsapp.alt_history', $exception->getMessage(), [
                'actor_id' => $user->id,
                'instance' => $instance['slug'],
                'payload' => $payload,
            ]);
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        return new JsonResponse([
            'ok' => true,
            'instance' => $instance['slug'],
            'stats' => $response['stats'] ?? [],
        ]);
    }

    public function sendViaGateway(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->guardWhatsappAccess($user);
        try {
            $instance = $this->resolveGatewayInstance($request);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 404);
        }

        $phone = (string)$request->request->get('phone');
        $message = (string)$request->request->get('message');

        if ($phone === '' || $message === '') {
            return new JsonResponse(['error' => 'Informe telefone e mensagem.'], 422);
        }

        try {
            $this->callGateway($instance, 'POST', '/send-message', [
                'phone' => $phone,
                'message' => $message,
            ]);
        } catch (RuntimeException $exception) {
            AlertService::push('whatsapp.alt_send', $exception->getMessage(), [
                'phone' => $phone,
                'actor_id' => $user->id,
                'instance' => $instance['slug'],
            ]);
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        return new JsonResponse(['ok' => true, 'instance' => $instance['slug']]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $instance = $this->resolveWebhookInstance($request);
        if ($instance === null) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $requestId = bin2hex(random_bytes(6));

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $this->logWebhookError('invalid_payload', 'JSON inválido recebido no webhook.', [
                'instance' => $instance['slug'] ?? 'unknown',
                'request_id' => $requestId,
                'raw' => $request->getContent(),
            ]);
            return new JsonResponse(['error' => 'invalid_payload'], 422);
        }

        // Debug: persist raw webhook for troubleshooting number parsing.
        $rawDir = base_path('storage/whatsapp_gateway_backups/raw');
        if (!is_dir($rawDir)) {
            @mkdir($rawDir, 0775, true);
        }
        $rawDumpFile = $rawDir . DIRECTORY_SEPARATOR . ($instance['slug'] ?? 'unknown') . '-' . $requestId . '.json';
        @file_put_contents($rawDumpFile, json_encode([
            'ts' => time(),
            'req' => $requestId,
            'instance' => $instance['slug'] ?? 'unknown',
            'headers' => $request->headers->all(),
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $logFile = base_path('storage/logs/whatsapp_alt_webhook.log');
        $logEntry = [
            'ts' => time(),
            'req' => $requestId,
            'instance' => $instance['slug'] ?? 'unknown',
            'direction' => $payload['direction'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'meta' => $payload['meta'] ?? null,
            'contact_hint' => $payload['contact_hint'] ?? null,
            'media' => $payload['media'] ?? null,
            'raw' => $payload,
            'payload_hash' => substr(hash('sha256', json_encode($payload)), 0, 12),
        ];
        @file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

        $direction = strtolower((string)($payload['direction'] ?? 'incoming'));
        if (!in_array($direction, ['incoming', 'outgoing', 'ack'], true)) {
            $direction = 'incoming';
        }

        $contactHint = $payload['contact_hint'] ?? [];
        if (!is_array($contactHint)) {
            $contactHint = [];
        }

        $meta = $payload['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        if (!isset($meta['profile']) && isset($contactHint['name']) && is_string($contactHint['name'])) {
            $meta['profile'] = $contactHint['name'];
        }

        $photoCandidates = [
            $contactHint['photo'] ?? null,
            $contactHint['picture'] ?? null,
            $contactHint['avatar'] ?? null,
            $contactHint['profilePicUrl'] ?? null,
            $contactHint['profile_pic'] ?? null,
            $contactHint['profile_picture'] ?? null,
            $meta['profile_photo'] ?? null,
        ];
        foreach ($photoCandidates as $candidate) {
            if (is_string($candidate) && str_starts_with($candidate, 'http')) {
                $meta['profile_photo'] = $candidate;
                break;
            }
        }

        // Try harder to resolve the sender phone using multiple hints from the payload and contact_hint.
        $pickPhone = static function (array $values): string {
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }
                $digits = preg_replace('/\D+/', '', $value) ?: '';
                if ($digits === '') {
                    continue;
                }
                // Accept likely phone lengths (10–15) or keep first non-empty as fallback.
                $len = strlen($digits);
                if ($len >= 10 && $len <= 15) {
                    return $digits;
                }
                if ($len > 0) {
                    return $digits;
                }
            }
            return '';
        };

        $hintPhone = $pickPhone([
            $payload['phone'] ?? null,
            $meta['participant']['phone'] ?? null,
            $meta['phone'] ?? null,
            $contactHint['phone'] ?? null,
            $contactHint['wa_id'] ?? null,
            $meta['wa_id'] ?? null,
            $payload['wa_id'] ?? null,
            $payload['from'] ?? null,
        ]);

        if (!isset($meta['meta_message_id']) && isset($meta['message_id'])) {
            $meta['meta_message_id'] = $meta['message_id'];
        }

        $lineLabel = $this->resolveConfiguredLineLabel($instance);

        if ($direction === 'ack') {
            try {
                $result = $this->whatsapp->registerGatewayAck((string)($payload['phone'] ?? ''), $meta);
                return new JsonResponse(['ok' => true, 'ack' => $result]);
            } catch (RuntimeException $exception) {
                // Não falhar com HTTP 500/422 para evitar retry em loop; logamos e retornamos ok=false.
                $errorClass = get_class($exception);
                AlertService::push('whatsapp.alt_webhook', $exception->getMessage(), [
                    'instance' => $instance['slug'],
                    'meta' => $meta,
                    'error_class' => $errorClass,
                ]);
                $this->logWebhookError('ack_error', $exception->getMessage(), [
                    'instance' => $instance['slug'],
                    'request_id' => $requestId,
                    'direction' => 'ack',
                    'phone' => $payload['phone'] ?? null,
                    'meta_message_id' => $meta['meta_message_id'] ?? ($meta['message_id'] ?? null),
                    'meta' => $meta,
                    'raw' => $payload,
                    'error_class' => $errorClass,
                    'error_file' => $exception->getFile(),
                    'error_line' => $exception->getLine(),
                    'trace' => $this->limitTrace($exception),
                ]);
                return new JsonResponse([
                    'ok' => false,
                    'warning' => 'ack_ignored',
                    'error' => $exception->getMessage(),
                    'error_class' => $errorClass,
                ]);
            }
        }

        $mediaPayload = $payload['media'] ?? null;
        if (!is_array($mediaPayload)) {
            $mediaPayload = null;
        }

        $rawPhone = $hintPhone !== '' ? $hintPhone : (string)($payload['phone'] ?? '');
        $normalizedPhone = $this->normalizeWebhookPhone($instance, $rawPhone, $meta);

        $entry = [
            'phone' => $normalizedPhone,
            'direction' => $direction,
            'message' => $payload['message'] ?? '',
            'contact_name' => $payload['contact_hint']['name'] ?? null,
            'timestamp' => $meta['timestamp'] ?? null,
            'line_label' => $lineLabel,
            'metadata' => $meta,
            'media' => $mediaPayload,
            'message_type' => $mediaPayload['type'] ?? ($payload['message_type'] ?? null),
        ];

        $this->logWebhookTrace([
            'phase' => 'normalized_entry',
            'request_id' => $requestId,
            'instance' => $instance['slug'] ?? 'unknown',
            'direction' => $direction,
            'raw_phone' => $rawPhone,
            'normalized_phone' => $normalizedPhone,
            'hint_phone' => $hintPhone,
            'channel_thread_id' => $payload['channel_thread_id'] ?? ($meta['channel_thread_id'] ?? null),
            'message_id' => $meta['meta_message_id'] ?? ($meta['message_id'] ?? ($payload['message_id'] ?? null)),
            'line_label' => $lineLabel,
            'payload_hash' => substr(hash('sha256', json_encode($payload)), 0, 12),
        ]);

        $this->whatsapp->backupGatewayIncoming([
            'phone' => $entry['phone'],
            'direction' => $direction,
            'message' => $entry['message'],
            'contact_name' => $entry['contact_name'] ?? null,
            'timestamp' => $entry['timestamp'] ?? null,
            'line_label' => $lineLabel,
            'instance' => $instance['slug'] ?? null,
            'meta' => $meta,
            'media' => $mediaPayload,
            'raw' => $payload,
        ]);

        try {
            $result = $this->whatsapp->ingestLogEntry($entry, ['mark_read' => false]);
            $this->logWebhookTrace([
                'phase' => 'ingest_ok',
                'request_id' => $requestId,
                'instance' => $instance['slug'] ?? 'unknown',
                'direction' => $direction,
                'phone' => $entry['phone'],
                'thread_id' => $result['thread_id'] ?? null,
                'meta_message_id' => $meta['meta_message_id'] ?? ($meta['message_id'] ?? null),
            ]);
            return new JsonResponse(['ok' => true, 'thread_id' => $result['thread_id']]);
        } catch (Throwable $exception) {
            // Evita 500 para o gateway; loga e responde ok=false para não ficar em retry infinito.
            $errorClass = get_class($exception);
            AlertService::push('whatsapp.alt_webhook', $exception->getMessage(), $entry + [
                'instance' => $instance['slug'],
                'error_class' => $errorClass,
            ]);
            $this->logWebhookError('ingest_error', $exception->getMessage(), [
                'instance' => $instance['slug'],
                'request_id' => $requestId,
                'direction' => $direction,
                'phone' => $payload['phone'] ?? null,
                'meta_message_id' => $meta['meta_message_id'] ?? ($meta['message_id'] ?? null),
                'line_label' => $lineLabel,
                'meta' => $meta,
                'raw' => $payload,
                'error_class' => $errorClass,
                'error_file' => $exception->getFile(),
                'error_line' => $exception->getLine(),
                'trace' => $this->limitTrace($exception),
            ]);
            $this->logWebhookTrace([
                'phase' => 'ingest_error',
                'request_id' => $requestId,
                'instance' => $instance['slug'] ?? 'unknown',
                'direction' => $direction,
                'phone' => $entry['phone'],
                'error' => $exception->getMessage(),
                'error_class' => $errorClass,
                'meta_message_id' => $meta['meta_message_id'] ?? ($meta['message_id'] ?? null),
            ]);
            return new JsonResponse([
                'ok' => false,
                'warning' => 'ingest_skipped',
                'error' => $exception->getMessage(),
                'error_class' => $errorClass,
                'error_file' => $exception->getFile(),
                'error_line' => $exception->getLine(),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function callGateway(array $instance, string $method, string $path, ?array $body = null, bool $expectJson = true): array
    {
        $baseUrl = rtrim((string)($instance['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Gateway URL nao configurada.');
        }

        $url = $baseUrl . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
            $headers[] = 'Content-Type: application/json';
        }

        if ($method === 'POST' && in_array($path, ['/send-message', '/reset-session', '/history-sync', '/shutdown'], true)) {
            $token = trim((string)($instance['command_token'] ?? ''));
            if ($token === '') {
                throw new RuntimeException('Token de comando do gateway nao configurado.');
            }
            $headers[] = 'X-Gateway-Token: ' . $token;
        }

        $timeout = (int)env('WHATSAPP_ALT_GATEWAY_HTTP_TIMEOUT', 10);
        $historyTimeout = (int)env('WHATSAPP_ALT_GATEWAY_HISTORY_TIMEOUT', 45);
        $connectTimeout = 8;
        if ($path === '/history-sync') {
            $timeout = max($timeout, max(30, $historyTimeout));
            $connectTimeout = min($timeout, 15);
        } elseif ($path === '/health') {
            // Health checks should fail fast to não travar a UI.
            $timeout = min($timeout, 4);
            $connectTimeout = min($timeout, 2);
        } elseif ($path === '/qr') {
            // QR pode ser um pouco mais lento, mas ainda limitado.
            $timeout = min($timeout, 8);
            $connectTimeout = min($timeout, 4);
        }

        if ($timeout < 4) {
            $timeout = 4;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch) ?: 'curl_error';
            curl_close($ch);
            throw new RuntimeException('Falha ao contatar gateway: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($status >= 400) {
            throw new RuntimeException('Gateway respondeu com erro HTTP ' . $status);
        }

        if ($expectJson === false) {
            return [
                'raw' => $raw ?? '',
                'content_type' => $contentType,
            ];
        }

        if ($raw === '' || $raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function coerceTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $intValue = (int)$value;
            return $intValue > 0 ? $intValue : null;
        }

        $parsed = strtotime((string)$value);
        return $parsed !== false ? $parsed : null;
    }

    private function normalizeWebhookPhone(array $instance, string $rawPhone, array $meta = []): string
    {
        // Se houver mapeamento manual de JID alt -> telefone real, aplica antes de qualquer fallback.
        $mappedPhone = $this->mapAltJidToPhone($rawPhone, $meta);

        $digits = preg_replace('/\D+/', '', $mappedPhone ?? $rawPhone) ?: '';
        $len = strlen($digits);
        $isBrazil = ($len === 10) || ($len === 11 && $digits[2] === '9');

        if ($isBrazil) {
            return format_phone($digits);
        }

        $hasPrivacyJid = false;
        if ($meta !== []) {
            $messageId = (string)($meta['meta_message_id'] ?? ($meta['message_id'] ?? ''));
            $chatId = (string)($meta['chat_id'] ?? '');
            $hasPrivacyJid = (str_contains($messageId, '@lid') || str_contains($chatId, '@lid'));
        }

        if ($digits !== '') {
            // Keep the mapped/raw digits for non-BR numbers (or privacy JIDs) to avoid overwriting with the line phone.
            return $digits;
        }

        if ($hasPrivacyJid) {
            return $rawPhone;
        }

        // If nothing usable came in the payload, fall back to the line phone to keep the thread reachable.
        $line = $this->whatsapp->lineByAltSlug((string)($instance['slug'] ?? ''));
        if ($line && !empty($line['display_phone'])) {
            $fallbackDigits = preg_replace('/\D+/', '', (string)$line['display_phone']) ?: '';
            if ($fallbackDigits !== '') {
                return format_phone($fallbackDigits);
            }
        }

        return $rawPhone;
    }

    private function mapAltJidToPhone(string $rawPhone, array $meta = []): ?string
    {
        $map = config('whatsapp_alt_jid_map', []);
        if (!is_array($map) || $map === []) {
            return null;
        }

        $candidates = [$rawPhone];
        $digitsRaw = preg_replace('/\D+/', '', $rawPhone) ?: '';
        if ($digitsRaw !== '') {
            $candidates[] = $digitsRaw;
        }

        $messageId = (string)($meta['meta_message_id'] ?? ($meta['message_id'] ?? ''));
        $chatId = (string)($meta['chat_id'] ?? '');
        if ($messageId !== '') {
            $candidates[] = $messageId;
        }
        if ($chatId !== '') {
            $candidates[] = $chatId;
        }

        foreach ($candidates as $key) {
            if (!is_string($key)) {
                continue;
            }
            if (isset($map[$key]) && is_string($map[$key]) && trim($map[$key]) !== '') {
                return $map[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildHistorySyncPayload(Request $request): array
    {
        $since = $this->coerceTimestamp($request->request->get('history_from'));
        $until = $this->coerceTimestamp($request->request->get('history_to'));
        $lookback = $this->sanitizeOptionalInt($request->request->get('history_lookback'), 5, 10080);
        $maxChats = $this->sanitizeOptionalInt($request->request->get('history_max_chats'), 1, null);
        $maxMessages = $this->sanitizeOptionalInt($request->request->get('history_max_messages'), 1, null);

        $payload = [
            'since' => $since,
            'until' => $until,
            'lookback_minutes' => $lookback,
            'max_chats' => $maxChats,
            'max_messages' => $maxMessages,
        ];

        return array_filter(
            $payload,
            static fn($value) => $value !== null && $value !== ''
        );
    }

    private function resolveGatewayInstance(Request $request, bool $allowDefault = true): array
    {
        $raw = $request->query->get('instance', $request->request->get('instance', ''));
        $slug = is_string($raw) ? trim($raw) : '';

        if ($slug !== '') {
            $instance = $this->whatsapp->altGatewayInstance($slug);
        } elseif ($allowDefault) {
            $instance = $this->whatsapp->defaultAltGatewayInstance();
        } else {
            $instance = null;
        }

        if ($instance === null) {
            throw new RuntimeException('Gateway alternativo nao configurado.');
        }

        return $instance;
    }

    private function resolveWebhookInstance(Request $request): ?array
    {
        $header = $this->resolveAuthorizationHeader($request);
        if ($header === '') {
            return null;
        }

        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : $header;
        $token = trim((string)$token);
        if ($token === '') {
            return null;
        }

        foreach ($this->whatsapp->altGatewayInstances() as $instance) {
            $configured = trim((string)($instance['webhook_token'] ?? ''));
            if ($configured !== '' && hash_equals($configured, $token)) {
                return $instance;
            }
        }

        return null;
    }

    private function sanitizeOptionalInt($value, int $min, ?int $max = null): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int)$value;
        if ($intValue < $min) {
            $intValue = $min;
        }
        if ($max !== null && $intValue > $max) {
            $intValue = $max;
        }

        return $intValue;
    }

    private function normalizeQrPayload(string $payload, string $contentType): ?string
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return null;
        }

        $candidate = null;
        $mime = trim($contentType);

        $firstChar = $trimmed[0] ?? '';
        if ($firstChar === '{' || $firstChar === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $candidate = $decoded['qr'] ?? $decoded['image'] ?? $decoded['data'] ?? $decoded['buffer'] ?? null;
                if (isset($decoded['mime']) && is_string($decoded['mime']) && trim($decoded['mime']) !== '') {
                    $mime = trim($decoded['mime']);
                }
            }
        }

        if ($candidate === null && preg_match('#^data:#i', $trimmed) === 1) {
            $candidate = $trimmed;
        }

        if ($candidate === null && preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        if ($candidate !== null) {
            return $this->ensureDataUri($candidate, $mime);
        }

        $dataUri = $this->buildDataUriFromBinary($payload, $mime);
        return $dataUri !== '' ? $dataUri : null;
    }

    private function ensureDataUri(string $value, ?string $mime): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('#^data:#i', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        $decoded = base64_decode($trimmed, true);
        if ($decoded === false) {
            $decoded = $trimmed;
        }

        return $this->buildDataUriFromBinary($decoded, $mime);
    }

    private function buildDataUriFromBinary(string $binary, ?string $mime): string
    {
        if ($binary === '') {
            return '';
        }

        $type = (string)$mime;
        if ($type === '' || stripos($type, 'image/') !== 0) {
            $type = 'image/png';
        }

        return 'data:' . $type . ';base64,' . base64_encode($binary);
    }

    private function launchGatewayProcess(array $instance): string
    {
        $script = $this->gatewayStartScript($instance);
        if ($script === '' || !is_file($script)) {
            throw new RuntimeException('Script de inicializacao do gateway nao localizado.');
        }

        $command = $this->buildGatewayStartCommand($script);

        $nullDevice = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'NUL' : '/dev/null';
        $descriptorSpec = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $nullDevice, 'w'],
            2 => ['file', $nullDevice, 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes, base_path());
        if (!is_resource($process)) {
            throw new RuntimeException('Falha ao disparar o processo do gateway.');
        }
        proc_close($process);

        return sprintf('Gateway %s em inicializacao. Aguarde alguns segundos e atualize o status.', $instance['label'] ?? 'alternativo');
    }

    private function gatewayStartScript(array $instance): string
    {
        $candidates = [];
        $configured = trim((string)($instance['start_command'] ?? ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $fallback = (string)env(
            'WHATSAPP_ALT_GATEWAY_START_COMMAND',
            (string)$this->settings->get('whatsapp.alt_gateway_start_command', '')
        );
        $fallback = trim($fallback);
        if ($fallback !== '') {
            $candidates[] = $fallback;
        }

        $slug = trim((string)($instance['slug'] ?? ''));
        $sessionHint = trim((string)($instance['session_hint'] ?? ''));
        $scriptNames = ['start-gateway.bat'];
        if ($slug !== '') {
            $scriptNames[] = 'start-gateway-' . $slug . '.bat';
        }
        if ($sessionHint !== '' && $sessionHint !== $slug) {
            $scriptNames[] = 'start-gateway-' . $sessionHint . '.bat';
        }

        $baseScripts = [
            base_path('services/whatsapp-web-gateway'),
        ];

        $parent = dirname(base_path());
        if ($parent !== '' && $parent !== base_path()) {
            $baseScripts[] = $parent . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'whatsapp-web-gateway';
        }

        foreach ($baseScripts as $baseDir) {
            foreach ($scriptNames as $scriptName) {
                $candidates[] = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $scriptName;
            }
        }

        $tested = [];
        foreach (array_unique($candidates) as $candidate) {
            $tested[] = $candidate;
            $resolved = $this->resolveGatewayStartCandidate($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new RuntimeException(
            sprintf(
                'Script de inicializacao do gateway nao localizado (caminhos testados: %s).',
                implode(' | ', $tested)
            )
        );
    }

    private function resolveGatewayStartCandidate(?string $candidate): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }

        $trimmed = trim($candidate);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);

        if ($this->isAbsolutePath($normalized)) {
            return is_file($normalized) ? (realpath($normalized) ?: $normalized) : null;
        }

        $paths = [
            base_path($normalized),
        ];

        $parent = dirname(base_path());
        if ($parent !== '' && $parent !== base_path()) {
            $paths[] = $parent . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
        }

        foreach ($paths as $path) {
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if (is_file($path)) {
                return realpath($path) ?: $path;
            }
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR || $path[0] === '/') {
            return true;
        }

        if (str_starts_with($path, '\\\\')) {
            return true;
        }

        return (bool)preg_match('#^[a-zA-Z]:\\\\#', $path);
    }

    private function buildGatewayStartCommand(string $script): string
    {
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $escapedPath = str_replace("'", "''", $script);
            $workingDir = str_replace("'", "''", dirname($script));
            return sprintf(
                "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Start-Process -FilePath '%s' -WorkingDirectory '%s' -WindowStyle Normal\"",
                $escapedPath,
                $workingDir
            );
        }

        $escaped = escapeshellarg($script);
        return 'nohup ' . $escaped . ' >/dev/null 2>&1 &';
    }

    private function requireUser(Request $request): AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            throw new RuntimeException('Usuário não autenticado.');
        }

        return $user;
    }

    private function guardWhatsappAccess(AuthenticatedUser $user): void
    {
        $options = $this->whatsapp->globalOptions();
        $allowed = $this->whatsapp->allowedUserIds();

        if (($options['block_avp_access'] ?? false) && $user->isAvp && !$user->isAdmin()) {
            throw new RuntimeException('Acesso bloqueado para usuários AVP.');
        }

        if ($user->isAdmin()) {
            return;
        }

        if ($allowed !== [] && !in_array($user->id, $allowed, true)) {
            throw new RuntimeException('Você não tem permissão para usar o WhatsApp alternativo.');
        }
    }



    private function resolveAuthorizationHeader(Request $request): string
    {
        $header = (string)$request->headers->get('Authorization', '');
        if ($header !== '') {
            return $header;
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                if (!empty($headers['Authorization'])) {
                    return (string)$headers['Authorization'];
                }
                if (!empty($headers['authorization'])) {
                    return (string)$headers['authorization'];
                }
            }
        }

        $serverHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        return $serverHeader !== '' ? $serverHeader : '';
    }


    private function resolveConfiguredLineLabel(array $instance): ?string
    {
        $label = trim((string)($instance['default_line_label'] ?? ''));
        if ($label === '') {
            $label = trim((string)env('WHATSAPP_ALT_GATEWAY_DEFAULT_LINE', 'WhatsApp Web Alternativo'));
        }
        if ($label === '') {
            return null;
        }

        $normalized = \mb_strtolower($label, 'UTF-8');
        foreach ($this->whatsapp->lines() as $line) {
            $candidate = \mb_strtolower((string)($line['label'] ?? ''), 'UTF-8');
            if ($candidate === $normalized) {
                return $label;
            }
        }

        return null;
    }

    private function logWebhookError(string $type, string $message, array $context = []): void
    {
        $record = [
            'ts' => time(),
            'type' => $type,
            'message' => $message,
            'instance' => $context['instance'] ?? 'unknown',
            'direction' => $context['direction'] ?? null,
            'phone' => $context['phone'] ?? null,
            'meta_message_id' => $context['meta_message_id'] ?? null,
            'line_label' => $context['line_label'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'payload_hash' => isset($context['raw']) ? substr(hash('sha256', json_encode($context['raw'])), 0, 12) : null,
            'meta' => $context['meta'] ?? null,
            'trace' => $context['trace'] ?? null,
        ];

        $record = array_filter($record, static fn($value) => $value !== null && $value !== '');
        @file_put_contents(
            base_path('storage/logs/whatsapp_alt_webhook_errors.log'),
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }

    private function logWebhookTrace(array $data): void
    {
        $record = ['ts' => time()] + $data;
        @file_put_contents(
            base_path('storage/logs/whatsapp_alt_trace.log'),
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }

    private function limitTrace(Throwable $exception, int $maxLength = 1200): string
    {
        $trace = $exception->getMessage() . "\n" . $exception->getTraceAsString();
        return strlen($trace) > $maxLength ? substr($trace, 0, $maxLength) . '...truncated' : $trace;
    }
}
