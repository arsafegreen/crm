<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';
require __DIR__ . '/../bootstrap/app.php';

$repo = new App\Repositories\EmailAccountRepository();
$accounts = $repo->all(true);

foreach ($accounts as $account) {
    printf(
        "ID:%d\n  Nome: %s\n  IMAP Host: %s\n  IMAP User: %s\n  IMAP Pass: %s\n  IMAP Enabled: %s\n  Último UID: %s\n\n",
        $account['id'],
        $account['name'] ?? '(sem nome)',
        $account['imap_host'] ?? '(vazio)',
        $account['imap_username'] ?? '(vazio)',
        $account['imap_password'] ? str_repeat('*', strlen((string)$account['imap_password'])) : '(vazio)',
        (int)($account['imap_sync_enabled'] ?? 0) === 1 ? 'sim' : 'não',
        $account['imap_last_uid'] ?? 'null'
    );
}
