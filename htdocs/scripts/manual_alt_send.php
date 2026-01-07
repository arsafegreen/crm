<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// Load helpers and environment (env + config cache).
require_once __DIR__ . '/../app/Support/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Auth\AuthenticatedUser;
use App\Services\Whatsapp\WhatsappService;

// Usage (PowerShell):
// php scripts/manual_alt_send.php --phone=11999999999 --name="Cliente" --message="Olá" --gateway=lab01 --queue=concluidos --campaign=renewal

$options = getopt('', [
    'phone:',
    'name::',
    'message:',
    'gateway::',
    'queue::',
    'campaign::',
    'token::',
]);

$phone = preg_replace('/\D+/', '', $options['phone'] ?? '');
$message = trim((string)($options['message'] ?? ''));
if ($phone === '' || $message === '') {
    fwrite(STDERR, "Uso: php scripts/manual_alt_send.php --phone=11999999999 --message=\"...\" [--name=Nome] [--gateway=lab01] [--queue=concluidos] [--campaign=renewal] [--token=123]\n");
    exit(1);
}

$name = trim((string)($options['name'] ?? 'Contato WhatsApp'));
$gateway = trim((string)($options['gateway'] ?? ''));
$queue = trim((string)($options['queue'] ?? 'concluidos'));
$campaignKind = trim((string)($options['campaign'] ?? ''));
$campaignToken = trim((string)($options['token'] ?? ''));

$actor = new AuthenticatedUser(
    id: 1,
    name: 'CLI Admin',
    email: 'cli@example.com',
    role: 'admin',
    fingerprint: 'cli-manual',
    cpf: null,
    permissions: ['all'],
    chatIdentifier: 'cli',
    chatDisplayName: 'CLI Tester'
);

$service = new WhatsappService();

try {
    $result = $service->startManualConversation([
        'contact_name' => $name,
        'contact_phone' => $phone,
        'message' => $message,
        'initial_queue' => $queue,
        'gateway_instance' => $gateway !== '' ? $gateway : null,
        'campaign_kind' => $campaignKind,
        'campaign_token' => $campaignToken,
    ], $actor, enforceDailyLimit: true, failOnGatewayError: true);

    echo json_encode(['ok' => true, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $e) {
    // Log full exception details to STDERR for debugging.
    fwrite(
        STDERR,
        sprintf(
            "[%s] Unhandled exception in manual_alt_send.php:\n%s\n",
            date('c'),
            (string) $e
        )
    );
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(2);
}
