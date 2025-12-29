<?php

declare(strict_types=1);

use App\Security\CsrfTokenManager;
use Symfony\Component\HttpFoundation\Response;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = realpath(__DIR__ . '/../../');
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return base_path('resources' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        static $cache = [];

        if ($cache === []) {
            $files = glob(config_path('*.php')) ?: [];
            foreach ($files as $file) {
                $name = basename($file, '.php');
                $cache[$name] = require $file;
            }
        }

        if (!str_contains($key, '.')) {
            return $cache[$key] ?? $default;
        }

        [$file, $path] = explode('.', $key, 2);
        $data = $cache[$file] ?? null;

        if (!is_array($data)) {
            return $default;
        }

        return array_reduce(
            explode('.', $path),
            static function ($carry, $segment) {
                if (is_array($carry) && array_key_exists($segment, $carry)) {
                    return $carry[$segment];
                }
                return null;
            },
            $data
        ) ?? $default;
    }
}

if (!function_exists('now')) {
    function now(): int
    {
        return time();
    }
}

if (!function_exists('digits_only')) {
    function digits_only(?string $value): string
    {
        return preg_replace('/\D+/', '', (string)$value) ?? '';
    }
}

if (!function_exists('format_document')) {
    function format_document(?string $value): string
    {
        $digits = digits_only($value);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 11) {
            return sprintf(
                '%s.%s.%s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 3),
                substr($digits, 9, 2)
            );
        }

        if (strlen($digits) === 14) {
            return sprintf(
                '%s.%s.%s/%s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 4),
                substr($digits, 12, 2)
            );
        }

        return trim((string)$value);
    }
}

if (!function_exists('format_phone')) {
    function format_phone(?string $value): string
    {
        if ($value !== null && str_starts_with((string)$value, 'group:')) {
            return 'Grupo WhatsApp';
        }

        $digits = digits_only($value);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') {
            $digits = substr($digits, 2);
        }

        $length = strlen($digits);
        if ($length === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7, 4));
        }

        if ($length === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6, 4));
        }

        if ($length === 9) {
            return sprintf('%s-%s', substr($digits, 0, 5), substr($digits, 5, 4));
        }

        if ($length === 8) {
            return sprintf('%s-%s', substr($digits, 0, 4), substr($digits, 4, 4));
        }

        // Para números maiores (12–15 dígitos), só tentamos "nacionalizar" se o prefixo for 55 (DDI BR).
        // Caso contrário, devolvemos os dígitos crus para não inventar DDD/linhas erradas (ex.: JID @lid).
        if ($length >= 12 && $length <= 15 && str_starts_with($digits, '55')) {
            $trimmed = substr($digits, -11);
            if (strlen($trimmed) === 11) {
                return sprintf('(%s) %s-%s', substr($trimmed, 0, 2), substr($trimmed, 2, 5), substr($trimmed, 7, 4));
            }
            $trimmed = substr($digits, -10);
            if (strlen($trimmed) === 10) {
                return sprintf('(%s) %s-%s', substr($trimmed, 0, 2), substr($trimmed, 2, 4), substr($trimmed, 6, 4));
            }
        }

        return $digits;
    }
}

if (!function_exists('money_to_cents')) {
    function money_to_cents(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int)round($value * 100);
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace(['R$', 'r$', ' '], '', $raw);
        $normalized = str_replace(['.'], '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        if (!is_numeric($normalized)) {
            return null;
        }

        return (int)round((float)$normalized * 100);
    }
}

if (!function_exists('money_from_cents')) {
    function money_from_cents(?int $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return $value / 100;
    }
}

if (!function_exists('format_money')) {
    function format_money(?int $value, bool $withCurrency = true): string
    {
        if ($value === null) {
            return '—';
        }

        $amount = money_from_cents($value);
        $formatted = number_format((float)$amount, 2, ',', '.');
        return $withCurrency ? 'R$ ' . $formatted : $formatted;
    }
}

if (!function_exists('format_money_input')) {
    function format_money_input(?int $value): string
    {
        if ($value === null) {
            return '';
        }

        $amount = money_from_cents($value);
        return number_format((float)$amount, 2, ',', '.');
    }
}

if (!function_exists('slugify')) {
    function slugify(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower((string)$value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value ?? '');
        $value = trim((string)$value, '-');
        return $value;
    }
}

if (!function_exists('base_uri')) {
    function base_uri(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir = rtrim(dirname($script), '/');
        if ($dir === '' || $dir === '.') {
            return '';
        }
        return $dir;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = base_uri();
        $path = ltrim($path, '/');

        if ($path === '') {
            return $base === '' ? '/' : $base;
        }

        return ($base === '' ? '' : $base) . '/' . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url(ltrim($path, '/'));
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?int $timestamp, string $format = 'd/m/Y H:i'): string
    {
        if ($timestamp === null) {
            return '-';
        }

        $tz = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
        return $dt->format($format);
    }
}

if (!function_exists('format_date')) {
    function format_date(?int $timestamp, string $format = 'd/m/Y'): string
    {
        return format_datetime($timestamp, $format);
    }
}

if (!function_exists('format_money')) {
    function format_money(?int $cents, string $prefix = 'R$'): string
    {
        if ($cents === null) {
            return '-';
        }

        $value = $cents / 100;
        $number = number_format($value, 2, ',', '.');
        return trim($prefix) === '' ? $number : ($prefix . ' ' . $number);
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return base_path('app' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('view_path')) {
    function view_path(string $path = ''): string
    {
        return resource_path('views' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = []): Response
    {
        $templatePath = view_path($template . '.php');
        if (!file_exists($templatePath)) {
            throw new RuntimeException("View {$template} not found at {$templatePath}");
        }

        $layoutKey = '_layout';
        $layout = isset($data[$layoutKey]) ? (string)$data[$layoutKey] : 'layouts/main';
        unset($data[$layoutKey]);

        $layoutPath = view_path($layout . '.php');
        if (!file_exists($layoutPath)) {
            throw new RuntimeException("Layout {$layout} not found at {$layoutPath}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;
        $content = ob_get_clean();

        ob_start();
        include $layoutPath;
        $html = ob_get_clean();

        return new Response($html);
    }
}

if (!function_exists('json_response')) {
    function json_response(array $payload, int $status = 200): Response
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $encoded = json_encode($payload, $options);

        if ($encoded === false) {
            $status = max(500, $status);
            $encoded = json_encode(['error' => 'Falha ao codificar a resposta.'], $options) ?: '{}';
        }

        return new Response(
            $encoded,
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}

if (!function_exists('abort')) {
    function abort(int $status, string $message = 'Erro'): Response
    {
        return new Response($message, $status);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return CsrfTokenManager::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_token" value="' . $token . '">';
    }
}
