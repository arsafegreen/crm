<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Services\Mail\SmtpMailer;
use App\Services\EmailAccountService;
use App\Services\Mail\MimeMessageBuilder;

$target = 'bersanetti@gmail.com';
$accountId = 3;

$accounts = new EmailAccountService();
$acc = $accounts->getAccount($accountId);
if ($acc === null) {
    fwrite(STDERR, "Conta {$accountId} não encontrada.\n");
    exit(1);
}

$creds = $acc['credentials'] ?? [];
$config = [
    'host' => $acc['smtp_host'],
    'port' => (int)$acc['smtp_port'],
    'encryption' => $acc['encryption'],
    'auth_mode' => $acc['auth_mode'],
    'username' => $creds['username'] ?? null,
    'password' => $creds['password_plain'] ?? $creds['password'] ?? null,
];

// The service normally returns sanitized credentials; we decrypt manually via EmailAccountService decode.
// 'password' should already be decrypted by getAccount(); if not, bail.
if (empty($config['password'])) {
    fwrite(STDERR, "Senha não carregada para a conta {$accountId}.\n");
    exit(1);
}

$mailer = new SmtpMailer($config);

$builder = new MimeMessageBuilder();
$message = $builder->build([
    'from_name' => $acc['from_name'],
    'from_email' => $acc['from_email'],
    'to' => [$target],
    'subject' => 'Teste SMTP conta contato@safegreen.com.br',
    'html' => '<p>Teste SMTP</p>',
    'text' => 'Teste SMTP',
]);

try {
    $mailer->send([
        'from' => $acc['from_email'],
        'recipients' => [$target],
        'data' => $message,
    ]);
    echo "ENVIO OK para {$target}\n";
} catch (Throwable $e) {
    echo "ENVIO FALHOU: " . $e->getMessage() . "\n";
}
