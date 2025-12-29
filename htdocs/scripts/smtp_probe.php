<?php

declare(strict_types=1);

// Minimal SMTP AUTH LOGIN probe with STARTTLS.
// Uses contato@safegreen.com.br credentials.

$host = 'server18.mailgrid.com.br';
$port = 587;
$user = 'contato@safegreen.com.br';
$pass = 'Edu020779@';

function expect($stream, string $label, int $timeout = 10): string
{
    stream_set_timeout($stream, $timeout);
    $data = '';
    while (!feof($stream)) {
        $line = fgets($stream, 4096);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    fwrite(STDOUT, "{$label}: {$data}");
    return $data;
}

$context = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ],
]);

$socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
if (!$socket) {
    fwrite(STDERR, "Falha ao conectar: {$errstr}\n");
    exit(1);
}

expect($socket, 'Banner');
fwrite($socket, "EHLO local.test\r\n");
expect($socket, 'EHLO');

// STARTTLS
fwrite($socket, "STARTTLS\r\n");
$resp = (string)expect($socket, 'STARTTLS');
$code = trim($resp);
if (strpos($code, '220') !== 0) {
    fwrite(STDERR, "STARTTLS não aceito.\n");
    exit(1);
}

// Enable crypto
if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
    fwrite(STDERR, "Falha ao iniciar TLS.\n");
    exit(1);
}

fwrite($socket, "EHLO local.test\r\n");
expect($socket, 'EHLO-TLS');

fwrite($socket, "AUTH LOGIN\r\n");
expect($socket, 'AUTH');

fwrite($socket, base64_encode($user) . "\r\n");
expect($socket, 'USER');

fwrite($socket, base64_encode($pass) . "\r\n");
expect($socket, 'PASS');

fwrite($socket, "QUIT\r\n");
expect($socket, 'QUIT');

fclose($socket);
