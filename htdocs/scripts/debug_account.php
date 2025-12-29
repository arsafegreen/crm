<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Services\EmailAccountService;

$target = strtolower('contato@safegreen.com.br');
$svc = new EmailAccountService();
$accounts = $svc->listAccounts(true);

$found = false;
foreach ($accounts as $account) {
    if (strtolower((string)($account['from_email'] ?? '')) !== $target) {
        continue;
    }
    $found = true;
    $creds = $account['credentials'] ?? [];
    $summary = [
        'id' => $account['id'] ?? null,
        'name' => $account['name'] ?? null,
        'provider' => $account['provider'] ?? null,
        'status' => $account['status'] ?? null,
        'warmup_status' => $account['warmup_status'] ?? null,
        'from_email' => $account['from_email'] ?? null,
        'from_name' => $account['from_name'] ?? null,
        'reply_to' => $account['reply_to'] ?? null,
        'smtp_host' => $account['smtp_host'] ?? null,
        'smtp_port' => $account['smtp_port'] ?? null,
        'encryption' => $account['encryption'] ?? null,
        'auth_mode' => $account['auth_mode'] ?? null,
        'username' => $creds['username'] ?? null,
        'has_password' => $creds['has_password'] ?? false,
        'limits' => [
            'hourly_limit' => $account['hourly_limit'] ?? null,
            'daily_limit' => $account['daily_limit'] ?? null,
            'burst_limit' => $account['burst_limit'] ?? null,
        ],
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

if (!$found) {
    echo "Conta não encontrada para from_email {$target}\n";
}
