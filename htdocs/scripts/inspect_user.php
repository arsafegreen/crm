<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

$email = $argv[1] ?? null;
if ($email === null) {
    fwrite(STDERR, "Usage: php scripts/inspect_user.php <email>\n");
    exit(1);
}

$repo = new \App\Repositories\UserRepository();
$user = $repo->findByEmail($email);
var_export($user);
