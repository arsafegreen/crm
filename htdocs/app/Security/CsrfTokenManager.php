<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

final class CsrfTokenManager
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY]) || $_SESSION[self::SESSION_KEY] === '') {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function regenerate(): string
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(Request $request): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $provided = self::extractToken($request);
        if ($provided === null) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private static function extractToken(Request $request): ?string
    {
        $token = $request->request->get('_token');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $headerToken = $request->headers->get('X-CSRF-TOKEN');
        if (is_string($headerToken) && $headerToken !== '') {
            return $headerToken;
        }

        $headerToken = $request->headers->get('X-XSRF-TOKEN');
        if (is_string($headerToken) && $headerToken !== '') {
            return $headerToken;
        }

        $jsonToken = self::extractFromJsonPayload($request);
        if (is_string($jsonToken) && $jsonToken !== '') {
            return $jsonToken;
        }

        return null;
    }

    private static function extractFromJsonPayload(Request $request): ?string
    {
        $contentType = (string)$request->headers->get('Content-Type', '');
        if (stripos($contentType, 'application/json') === false) {
            return null;
        }

        $content = $request->getContent();
        if ($content === '' || $content === false) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $token = $decoded['_token'] ?? null;
        return is_string($token) ? $token : null;
    }
}
