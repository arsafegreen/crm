<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class Totp
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1; // allow +/- steps for clock drift

    public function generateSecret(int $length = 20): string
    {
        if ($length < 10) {
            throw new RuntimeException('Comprimento mínimo para o segredo TOTP é 10 bytes.');
        }

        $random = random_bytes($length);
        return rtrim(Base32::encode($random), '=');
    }

    public function provisioningUri(string $secret, string $label, string $issuer): string
    {
        $encodedLabel = rawurlencode($label);
        $encodedIssuer = rawurlencode($issuer);
        $encodedSecret = rawurlencode($secret);

        return sprintf('otpauth://totp/%s?secret=%s&issuer=%s&period=%d&digits=%d',
            $encodedLabel,
            $encodedSecret,
            $encodedIssuer,
            self::PERIOD,
            self::DIGITS
        );
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $timestamp = $timestamp ?? time();
        $code = trim($code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $secretBinary = Base32::decode($secret);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $counter = intdiv($timestamp, self::PERIOD) + $offset;
            $calculated = $this->codeForCounter($secretBinary, $counter);
            if (hash_equals($calculated, $code)) {
                return true;
            }
        }

        return false;
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $chunk = substr($hash, $offset, 4);
        $value = unpack('N', $chunk)[1] & 0x7FFFFFFF;
        $code = $value % (10 ** self::DIGITS);

        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }
}
