<?php
// Temporary script to create an initial admin user. Run via CLI and delete after use.
// Usage examples:
//   php scripts/create_admin.php --email="admin@example.com" --name="Admin" --password="StrongPass123" 
// If --password is omitted, you will be prompted securely.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Repositories\UserRepository;
use App\Database\Connection;
use PDO;

function promptHidden(string $prompt): string
{
    if (function_exists('sapi_windows_cp_set')) {
        // Windows: fall back to visible input if no better option.
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

$options = getopt('', ['email:', 'name::', 'password::']);
$email = isset($options['email']) ? trim((string)$options['email']) : '';
$name = isset($options['name']) ? trim((string)$options['name']) : 'Admin Inicial';
$password = isset($options['password']) ? (string)$options['password'] : '';

if ($email === '') {
    fwrite(STDERR, "Informe --email=...\n");
    exit(1);
}

if ($password === '') {
    $password = promptHidden('Senha provisória (será hash): ');
}

if ($password === '') {
    fwrite(STDERR, "Senha não pode ser vazia.\n");
    exit(1);
}

$repo = new UserRepository();
$pdo = Connection::instance();

// Check existing
if ($repo->findByEmail($email) !== null) {
    fwrite(STDERR, "Já existe usuário com este e-mail. Nada feito.\n");
    exit(1);
}

$now = now();
$fingerprint = 'adm_' . bin2hex(random_bytes(12));
$hash = password_hash($password, PASSWORD_ARGON2ID);

$payload = [
    'cpf' => null,
    'name' => $name,
    'email' => mb_strtolower($email),
    'role' => 'admin',
    'status' => 'active',
    'certificate_fingerprint' => $fingerprint,
    'certificate_subject' => null,
    'certificate_serial' => null,
    'certificate_valid_to' => null,
    'last_seen_at' => null,
    'approved_at' => $now,
    'approved_by' => 'bootstrap',
    'created_at' => $now,
    'updated_at' => $now,
    'password_hash' => $hash,
    'password_updated_at' => $now,
    'totp_secret' => null,
    'totp_enabled' => 0,
    'totp_confirmed_at' => null,
    'last_login_at' => null,
    'permissions' => json_encode(['admin']),
    'session_token' => null,
    'session_forced_at' => null,
    'failed_login_attempts' => 0,
    'locked_until' => null,
    'previous_password_hash' => null,
    'chat_identifier' => null,
    'chat_display_name' => $name,
];

// Some columns may not exist in older schemas; filter dynamically.
$columns = [];
if ($pdo !== null) {
    $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $columns = array_column($cols, 'name');
}

if ($columns !== []) {
    $payload = array_filter(
        $payload,
        static fn($value, $key) => in_array($key, $columns, true),
        ARRAY_FILTER_USE_BOTH
    );
}

$repo->create($payload);

echo "Admin criado com e-mail {$email}. Altere a senha no primeiro login.\n";
