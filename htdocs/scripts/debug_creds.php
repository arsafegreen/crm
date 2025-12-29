<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';

use App\Repositories\EmailAccountRepository;
use App\Support\Crypto;

$id = 3;
$repo = new EmailAccountRepository();
$acc = $repo->find($id);
if ($acc === null) {
    fwrite(STDERR, "Conta nao encontrada\n");
    exit(1);
}
$raw = json_decode($acc['credentials'], true) ?: [];
$decrypt = function($v){
    if (!is_string($v)) return $v;
    if (!str_starts_with($v, 'enc:')) return $v;
    return Crypto::decrypt(substr($v, 4));
};
$raw['password_plain'] = $decrypt($raw['password'] ?? null);
$raw['oauth_plain'] = $decrypt($raw['oauth_token'] ?? null);
$raw['api_plain'] = $decrypt($raw['api_key'] ?? null);

$acc['credentials_decrypted'] = $raw;

echo json_encode($acc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
