<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Services\EmailAccountService;

$accountId = 3; // contato@safegreen.com.br
$newPassword = 'Edu020779@';

$svc = new EmailAccountService();
$account = $svc->getAccount($accountId);

if ($account === null) {
    fwrite(STDERR, "Conta {$accountId} não encontrada.\n");
    exit(1);
}

// Merge existing data with the new password
$payload = [
    'name' => $account['name'] ?? '',
    'provider' => $account['provider'] ?? 'custom',
    'domain' => $account['domain'] ?? null,
    'from_name' => $account['from_name'] ?? '',
    'from_email' => $account['from_email'] ?? '',
    'reply_to' => $account['reply_to'] ?? null,
    'smtp_host' => $account['smtp_host'] ?? '',
    'smtp_port' => (int)($account['smtp_port'] ?? 587),
    'encryption' => $account['encryption'] ?? 'tls',
    'auth_mode' => $account['auth_mode'] ?? 'login',
    'username' => $account['credentials']['username'] ?? $account['from_email'] ?? '',
    'password' => $newPassword,
    'oauth_token' => null,
    'api_key' => null,
    'headers' => $account['headers'] ?? null,
    'settings' => $account['settings'] ?? null,
    'hourly_limit' => (int)($account['hourly_limit'] ?? 2000),
    'daily_limit' => (int)($account['daily_limit'] ?? 48000),
    'burst_limit' => (int)($account['burst_limit'] ?? 14),
    'limit_source' => 'manual',
    'warmup_status' => $account['warmup_status'] ?? 'ready',
    'status' => $account['status'] ?? 'active',
    'notes' => $account['notes'] ?? null,
    'policies' => $account['policies'] ?? [],
];

$svc->updateAccount($accountId, $payload, null);

fwrite(STDOUT, "Senha atualizada para a conta {$accountId} ({$account['from_email']}).\n");
