<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12; // GCM recommended
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($ciphertext === false) {
            throw new RuntimeException('Falha ao criptografar dado sensível.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RuntimeException('Carga criptografada inválida.');
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Não foi possível descriptografar.');
        }

        return $plaintext;
    }

    private static function key(): string
    {
        $raw = $_ENV['EMAIL_CREDENTIAL_KEY'] ?? getenv('EMAIL_CREDENTIAL_KEY');
        if (!is_string($raw) || strlen($raw) < 32) {
            throw new RuntimeException('Chave EMAIL_CREDENTIAL_KEY ausente ou curta. Use 32+ caracteres.');
        }
        return substr(hash('sha256', $raw, true), 0, 32);
    }
}
