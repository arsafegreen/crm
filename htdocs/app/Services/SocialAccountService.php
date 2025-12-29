<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SocialAccountRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SocialAccountService
{
    private SocialAccountRepository $repository;

    public function __construct()
    {
        $this->repository = new SocialAccountRepository();
    }

    public function listAccounts(): array
    {
        return $this->repository->all();
    }

    public function createAccount(array $input): void
    {
        $platform = trim((string)($input['platform'] ?? ''));
        $label = trim((string)($input['label'] ?? ''));
        $token = trim((string)($input['token'] ?? ''));
        $externalId = trim((string)($input['external_id'] ?? ''));
        $expiresAt = trim((string)($input['expires_at'] ?? ''));

        if ($platform === '' || !in_array($platform, ['facebook', 'instagram', 'whatsapp', 'linkedin'], true)) {
            throw new InvalidArgumentException('Plataforma inválida.');
        }

        if ($label === '') {
            throw new InvalidArgumentException('Informe um apelido para reconhecer o canal.');
        }

        if ($token === '') {
            throw new InvalidArgumentException('O token de acesso é obrigatório.');
        }

        $expiresTimestamp = null;
        if ($expiresAt !== '') {
            $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $expiresAt, $timezone)
                ?: DateTimeImmutable::createFromFormat('Y-m-d', $expiresAt, $timezone);

            if ($dt === false) {
                throw new InvalidArgumentException('Data de expiração inválida.');
            }

            $expiresTimestamp = $dt->getTimestamp();
        }

        $this->repository->insert([
            'platform' => $platform,
            'label' => $label,
            'token' => $token,
            'external_id' => $externalId ?: null,
            'expires_at' => $expiresTimestamp,
        ]);
    }
}
