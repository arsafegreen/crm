<?php

declare(strict_types=1);

// Minimal SMTP AUTH LOGIN probe with STARTTLS for safegreen@safegreen.com.br.

$host = 'server18.mailgrid.com.br';
$port = 587;
$user = 'safegreen@safegreen.com.br';
$pass = 'OQ7QwWF2HvhD';

function expectLine($stream, string $label, int $timeout = 10): string
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
    return (string)$data;
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

expectLine($socket, 'Banner');
fwrite($socket, "EHLO local.test\r\n");
expectLine($socket, 'EHLO');

fwrite($socket, "STARTTLS\r\n");
$resp = trim(expectLine($socket, 'STARTTLS'));
if (strpos($resp, '220') !== 0) {
    fwrite(STDERR, "STARTTLS não aceito.\n");
    exit(1);
}

if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
    fwrite(STDERR, "Falha ao iniciar TLS.\n");
    exit(1);
}

fwrite($socket, "EHLO local.test\r\n");
expectLine($socket, 'EHLO-TLS');

fwrite($socket, "AUTH LOGIN\r\n");
expectLine($socket, 'AUTH');

fwrite($socket, base64_encode($user) . "\r\n");
expectLine($socket, 'USER');

fwrite($socket, base64_encode($pass) . "\r\n");
expectLine($socket, 'PASS');

fwrite($socket, "QUIT\r\n");
expectLine($socket, 'QUIT');

fclose($socket);
