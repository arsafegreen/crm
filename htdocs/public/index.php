<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Response;

$customLog = realpath(__DIR__ . '/../../php/logs/php_error_log');
if ($customLog !== false) {
    ini_set('error_log', $customLog);
}
ini_set('log_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null) {
        error_log(sprintf('[fatal] %s in %s on line %d', $err['message'], $err['file'], $err['line']));
    }
});

$request = require __DIR__ . '/../bootstrap/app.php';

$forceHttps = config('app.force_https', false);
if ($forceHttps) {
    $httpsFlag = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    if (!in_array($httpsFlag, ['on', '1'], true) && $forwardedProto !== 'https') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? parse_url((string)config('app.url'), PHP_URL_HOST) ?? 'localhost');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $target = 'https://' . $host . $uri;

        header('Location: ' . $target, true, 301);
        exit;
    }
}

$kernel = new Kernel();

try {
    $response = $kernel->handle($request);
} catch (Throwable $exception) {
    $status = config('app.debug') ? 500 : 503;

    // Log any unhandled exception reaching the front controller
    error_log('[front] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
    error_log($exception->getTraceAsString());

    if (config('app.debug')) {
        $body = sprintf(
            '<h1>Erro</h1><pre>%s</pre>',
            htmlspecialchars((string)$exception, ENT_QUOTES, 'UTF-8')
        );
        $response = new Response($body, $status);
    } else {
        $response = new Response('Ocorreu um erro inesperado.', $status);
    }
}

$response->send();
