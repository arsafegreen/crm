<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const HEADER = "ENC1";

    public static function encrypt(string $plaintext, string $key): string
    {
        $material = self::normalizeKey($key);

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Extensão OpenSSL é obrigatória para criptografar o banco de dados.');
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $material,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('Falha ao criptografar conteúdo do banco de dados.');
        }

        return base64_encode(self::HEADER . $iv . $tag . $ciphertext);
    }

    public static function decrypt(string $ciphertext, string $key): string
    {
        $material = self::normalizeKey($key);

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Extensão OpenSSL é obrigatória para descriptografar o banco de dados.');
        }

        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) < strlen(self::HEADER) + self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Formato de arquivo criptografado inválido.');
        }

        $header = substr($decoded, 0, strlen(self::HEADER));
        if ($header !== self::HEADER) {
            throw new RuntimeException('Conteúdo criptografado com cabeçalho desconhecido.');
        }

        $offset = strlen(self::HEADER);
        $iv = substr($decoded, $offset, self::IV_LENGTH);
        $offset += self::IV_LENGTH;
        $tag = substr($decoded, $offset, self::TAG_LENGTH);
        $offset += self::TAG_LENGTH;
        $payload = substr($decoded, $offset);

        $plaintext = openssl_decrypt(
            $payload,
            self::CIPHER,
            $material,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($plaintext === false) {
            throw new RuntimeException('Não foi possível descriptografar o arquivo do banco de dados.');
        }

        return $plaintext;
    }

    private static function normalizeKey(string $key): string
    {
        $trimmed = trim($key);
        if ($trimmed === '' || $trimmed === 'base64:') {
            throw new RuntimeException('A variável DB_ENCRYPTION_KEY precisa ser configurada com uma chave válida.');
        }

        if (str_starts_with($trimmed, 'base64:')) {
            $decoded = base64_decode(substr($trimmed, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
            throw new RuntimeException('DB_ENCRYPTION_KEY em formato base64 inválido.');
        }

        $decoded = base64_decode($trimmed, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        if (ctype_xdigit($trimmed) && strlen($trimmed) === 64) {
            $decoded = hex2bin($trimmed);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        if (strlen($trimmed) === 32) {
            return $trimmed;
        }

        throw new RuntimeException('A chave de criptografia deve ter 32 bytes (base64, hexadecimal ou texto plano).');
    }

    private function __construct()
    {
    }
}
