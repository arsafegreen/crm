<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

use App\Auth\PasswordPolicy;
use App\Repositories\UserRepository;

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($email === null || $password === null) {
    fwrite(STDERR, "Uso: php scripts/reset_password.php <email> <nova_senha>\n");
    exit(1);
}

$email = trim(strtolower($email));
$password = trim($password);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Informe um email válido.\n");
    exit(1);
}

if ($password === '') {
    fwrite(STDERR, "A nova senha não pode ser vazia.\n");
    exit(1);
}

$repo = new UserRepository();
$user = $repo->findByEmail($email);

if ($user === null) {
    fwrite(STDERR, "Usuário não encontrado: {$email}\n");
    exit(1);
}

$currentHash = $user['password_hash'] ?? null;
$previousHash = $user['previous_password_hash'] ?? null;

$error = PasswordPolicy::validate($password, $currentHash, $previousHash);
if ($error !== null) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_ARGON2ID);
$repo->updatePassword((int)$user['id'], $hash);

echo "Senha atualizada com sucesso para {$email}." . PHP_EOL;
