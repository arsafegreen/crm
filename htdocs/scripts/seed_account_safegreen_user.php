<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Services\EmailAccountService;

$svc = new EmailAccountService();

$data = [
    'name' => 'safegreen-user',
    'provider' => 'mailgrid',
    'domain' => null,
    'from_name' => 'SafeGreen Certificado Digital',
    'from_email' => 'safegreen@safegreen.com.br',
    'reply_to' => null,
    'smtp_host' => 'server18.mailgrid.com.br',
    'smtp_port' => 587,
    'encryption' => 'tls',
    'auth_mode' => 'login',
    'username' => 'safegreen@safegreen.com.br',
    'password' => 'OQ7QwWF2HvhD',
    'hourly_limit' => 2000,
    'daily_limit' => 48000,
    'burst_limit' => 14,
    'limit_source' => 'preset',
    'warmup_status' => 'ready',
    'status' => 'active',
    'notes' => 'Conta criada via script com senha fornecida pelo usuário.',
    'headers' => null,
    'settings' => null,
    'policies' => [],
];

$id = $svc->createAccount($data, null);

fwrite(STDOUT, "Conta safegreen@safegreen.com.br criada com ID: {$id}\n");
