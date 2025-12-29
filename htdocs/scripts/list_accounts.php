<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Services\EmailAccountService;

$svc = new EmailAccountService();
$accounts = $svc->listAccounts(true);

foreach ($accounts as $acc) {
    $creds = $acc['credentials'] ?? [];
    $row = [
        'id' => $acc['id'] ?? null,
        'name' => $acc['name'] ?? null,
        'from_email' => $acc['from_email'] ?? null,
        'provider' => $acc['provider'] ?? null,
        'status' => $acc['status'] ?? null,
        'smtp_host' => $acc['smtp_host'] ?? null,
        'smtp_port' => $acc['smtp_port'] ?? null,
        'encryption' => $acc['encryption'] ?? null,
        'auth_mode' => $acc['auth_mode'] ?? null,
        'username' => $creds['username'] ?? null,
        'has_password' => $creds['has_password'] ?? false,
    ];
    echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
