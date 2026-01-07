<?php
// Cria um admin no banco MySQL do Network (isolado do CRM).
// Uso: php create_admin.php --email="admin@dominio" --name="Admin" --password="SenhaForte"
// Requer config/database.php preenchido.

declare(strict_types=1);

require __DIR__ . '/../lib/db.php';

ensureSchema();

$options = getopt('', ['email:', 'name::', 'password::']);
$email = isset($options['email']) ? trim((string)$options['email']) : '';
$name = isset($options['name']) ? trim((string)$options['name']) : 'Admin Network';
$password = isset($options['password']) ? (string)$options['password'] : '';

if ($email === '') {
    fwrite(STDERR, "Informe --email=...\n");
    exit(1);
}

if ($password === '') {
    $password = promptHidden('Senha provisória: ');
}

if ($password === '') {
    fwrite(STDERR, "Senha não pode ser vazia.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_ARGON2ID);
if ($hash === false) {
    fwrite(STDERR, "Falha ao gerar hash.\n");
    exit(1);
}

$pdo = db();

$check = $pdo->prepare('SELECT 1 FROM network_admins WHERE email = :email');
$check->execute(['email' => strtolower($email)]);
if ($check->fetch()) {
    fwrite(STDERR, "Já existe admin com este e-mail no Network. Nada feito.\n");
    exit(1);
}

$stmt = $pdo->prepare('INSERT INTO network_admins (name, email, password_hash, created_at) VALUES (:name, :email, :password_hash, :created_at)');
$stmt->execute([
    'name' => $name,
    'email' => strtolower($email),
    'password_hash' => $hash,
    'created_at' => date('Y-m-d H:i:s'),
]);

echo "Admin Network criado para {$email}. Troque a senha no primeiro login.\n";

function promptHidden(string $prompt): string
{
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        echo $prompt;
        return trim((string)fgets(STDIN));
    }

    $sttyMode = null;
    if (function_exists('shell_exec')) {
        $sttyMode = shell_exec('stty -g');
        shell_exec('stty -echo');
    }
    echo $prompt;
    $line = trim((string)fgets(STDIN));
    if ($sttyMode !== null) {
        shell_exec(sprintf('stty %s', $sttyMode));
    }
    echo PHP_EOL;
    return $line;
}
