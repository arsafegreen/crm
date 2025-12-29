<?php

declare(strict_types=1);

namespace App\Auth;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public static function validate(string $password, ?string $currentHash = null, ?string $previousHash = null): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'A senha deve ter pelo menos 8 caracteres.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return 'Inclua pelo menos uma letra maiúscula na senha.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            return 'Inclua pelo menos uma letra minúscula na senha.';
        }

        if (!preg_match('/\d/', $password)) {
            return 'Inclua pelo menos um número na senha.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Inclua pelo menos um caractere especial na senha.';
        }

        if ($currentHash !== null && $currentHash !== '' && password_verify($password, $currentHash)) {
            return 'A nova senha não pode ser igual à senha atual.';
        }

        if ($previousHash !== null && $previousHash !== '' && password_verify($password, $previousHash)) {
            return 'A nova senha não pode repetir a senha utilizada anteriormente.';
        }

        return null;
    }
}
