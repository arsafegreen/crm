<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Services\EmailAccountService;

$service = new EmailAccountService();
$fromEmail = 'marketing@seu-dominio.com.br';

$existing = $service->listAccounts(true);
foreach ($existing as $account) {
    if (strcasecmp((string)($account['from_email'] ?? ''), $fromEmail) === 0) {
        fwrite(STDOUT, "Já existe conta com este remetente. ID: {$account['id']}\n");
        exit(0);
    }
}

$data = [
    'name' => 'MailGrid Prod',
    'provider' => 'mailgrid',
    'domain' => null,
    'from_name' => 'Equipe Marketing',
    'from_email' => $fromEmail,
    'reply_to' => null,
    'smtp_host' => 'server18.mailgrid.com.br',
    'smtp_port' => 587,
    'encryption' => 'tls',
    'auth_mode' => 'login',
    'username' => $fromEmail,
    'password' => 'CHANGE-ME-BEFORE-SEND',
    'hourly_limit' => 2000,
    'daily_limit' => 48000,
    'burst_limit' => 14,
    'limit_source' => 'preset',
    'warmup_status' => 'ready',
    'status' => 'disabled',
    'notes' => 'Placeholder criada via script; substitua senha e ative antes de enviar.',
    'headers' => null,
    'settings' => null,
    'policies' => [],
];

$id = $service->createAccount($data, null);
fwrite(STDOUT, "Conta criada com ID: {$id}\n");
