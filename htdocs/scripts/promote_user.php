<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

use App\Auth\Permissions;
use App\Repositories\UserRepository;

$email = $argv[1] ?? null;
if ($email === null) {
    fwrite(STDERR, "Uso: php scripts/promote_user.php <email>\n");
    exit(1);
}

$email = trim(strtolower($email));
if ($email === '') {
    fwrite(STDERR, "Informe um email válido.\n");
    exit(1);
}

$repo = new UserRepository();
$user = $repo->findByEmail($email);

if ($user === null) {
    fwrite(STDERR, "Usuário não encontrado: {$email}\n");
    exit(1);
}

$repo->update((int)$user['id'], [
    'role' => 'admin',
    'status' => 'active',
    'approved_at' => now(),
    'approved_by' => 'manual-promote',
    'permissions' => Permissions::validKeys(),
]);

echo "Usuário {$email} promovido para administrador e ativado." . PHP_EOL;
