<?php

use App\Repositories\EmailAccountRepository;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';
require __DIR__ . '/../bootstrap/app.php';

$accountId = 3; // contato@safegreen.com.br
$repo = new EmailAccountRepository();

$repo->update($accountId, [
    'imap_host' => 'mail.safegreen.com.br',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'imap_username' => 'contato@safegreen.com.br',
    'imap_password' => 'Elc@021120',
    'imap_sync_enabled' => 1,
]);

echo "Updated IMAP settings for account {$accountId}." . PHP_EOL;
