<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Support/helpers.php';

$basePath = realpath(__DIR__ . '/..');

if (file_exists($basePath . '/.env')) {
    Dotenv::createImmutable($basePath)->load();
}

$timezone = config('app.timezone', 'America/Sao_Paulo');
date_default_timezone_set($timezone);

$runningInCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

// Evita warnings na saída web (ex: módulo openssl já carregado) que quebram JSON.
if (!$runningInCli) {
    @ini_set('display_errors', '0');
    @error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

if (!$runningInCli && session_status() === PHP_SESSION_NONE) {
    $httpsFlag = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $secureCookie = config('app.force_https', false)
        || $httpsFlag === 'on'
        || $httpsFlag === '1'
        || $forwardedProto === 'https';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

return Request::createFromGlobals();
