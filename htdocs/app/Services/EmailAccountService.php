<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmailAccountRepository;
use App\Support\EmailProviderLimitDefaults;
use App\Support\Crypto;
use InvalidArgumentException;
use RuntimeException;

final class EmailAccountService
{
    private EmailAccountRepository $repository;

    /** @var string[] */
    private array $allowedProviders = ['gmail', 'outlook', 'hotmail', 'office365', 'zoho', 'amazonses', 'sendgrid', 'mailtrap', 'mailgrid', 'custom'];

    /** @var string[] */
    private array $allowedEncryption = ['none', 'ssl', 'tls'];

    /** @var string[] */
    private array $allowedStatuses = ['active', 'paused', 'disabled'];

    /** @var string[] */
    private array $allowedWarmupStatuses = ['ready', 'warming', 'cooldown'];

    /** @var string[] */
    private array $allowedAuthModes = ['login', 'oauth2', 'apikey'];

    public function __construct()
    {
        $this->repository = new EmailAccountRepository();
        $domainHourCap = (int)config('email.custom_domain_hourly_cap', 2000);
        $globalHourCap = (int)config('email.custom_global_hourly_cap', 2000);
        $this->customDomainHourlyCap = (int)max(1, ceil($domainHourCap / 60));
        $this->customGlobalHourlyCap = (int)max(1, ceil($globalHourCap / 60));
    }

    private int $customDomainHourlyCap = 2000;
    private int $customGlobalHourlyCap = 2000;

    public function listAccounts(bool $includeDeleted = false): array
    {
        $accounts = $this->repository->all($includeDeleted);
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $accounts);
        $policies = $this->repository->listPolicies($ids);

        foreach ($accounts as &$account) {
            $accountId = (int)$account['id'];
            $credentialsRaw = $this->decodeJson((string)$account['credentials'], false);
            $account['credentials'] = $this->sanitizeCredentialsForOutput($credentialsRaw);
            $account['headers'] = $this->decodeJson($account['headers'] ?? null);
            $account['settings'] = $this->decodeJson($account['settings'] ?? null);
            $account['policies'] = $policies[$accountId] ?? [];
        }

        return $accounts;
    }

    public function getAccount(int $id): ?array
    {
        $account = $this->repository->find($id);
        if ($account === null) {
            return null;
        }

        $credentialsRaw = $this->decodeJson((string)$account['credentials'], false);
        $account['credentials'] = $this->sanitizeCredentialsForOutput($credentialsRaw);
        $account['headers'] = $this->decodeJson($account['headers'] ?? null);
        $account['settings'] = $this->decodeJson($account['settings'] ?? null);
        $policies = $this->repository->listPolicies([$id]);
        $account['policies'] = $policies[$id] ?? [];

        return $account;
    }

    public function createAccount(array $input, ?int $actorId = null): int
    {
        $payload = $this->buildPayload($input, $actorId);
        $accountId = $this->repository->insert($payload);
        $policies = $this->normalizePolicies($input['policies'] ?? []);
        $this->repository->replacePolicies($accountId, $policies);

        return $accountId;
    }

    public function updateAccount(int $id, array $input, ?int $actorId = null): void
    {
        $existing = $this->repository->find($id);
        if ($existing === null) {
            throw new RuntimeException('Conta de envio não encontrada.');
        }

        $payload = $this->buildPayload($input, $actorId, $existing);
        $this->repository->update($id, $payload);
        $policies = $this->normalizePolicies($input['policies'] ?? []);
        $this->repository->replacePolicies($id, $policies);
    }

    public function archiveAccount(int $id, ?int $actorId = null): void
    {
        $existing = $this->repository->find($id);
        if ($existing === null) {
            throw new RuntimeException('Conta de envio não encontrada.');
        }

        if ((int)($existing['deleted_at'] ?? 0) !== 0) {
            return;
        }

        $this->repository->softDelete($id, $actorId);
    }

    private function buildPayload(array $input, ?int $actorId = null, ?array $existing = null): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Informe um nome para identificar a conta.');
        }

        $provider = strtolower(trim((string)($input['provider'] ?? '')));
        if ($provider === '') {
            $provider = 'custom';
        }
        if (!in_array($provider, $this->allowedProviders, true)) {
            throw new InvalidArgumentException('Provedor de e-mail inválido.');
        }

        $domain = trim((string)($input['domain'] ?? ''));

        $fromName = trim((string)($input['from_name'] ?? ''));
        $fromEmail = trim((string)($input['from_email'] ?? ''));
        if ($fromName === '' || $fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Defina nome e e-mail de remetente válidos.');
        }

        $smtpHost = trim((string)($input['smtp_host'] ?? ''));
        if ($smtpHost === '') {
            throw new InvalidArgumentException('Host SMTP é obrigatório.');
        }

        $smtpPort = (int)($input['smtp_port'] ?? 587);
        if ($smtpPort <= 0) {
            throw new InvalidArgumentException('Porta SMTP inválida.');
        }

        $encryption = strtolower(trim((string)($input['encryption'] ?? 'tls')));
        if (!in_array($encryption, $this->allowedEncryption, true)) {
            throw new InvalidArgumentException('Tipo de criptografia inválido.');
        }

        $authMode = strtolower(trim((string)($input['auth_mode'] ?? 'login')));
        if (!in_array($authMode, $this->allowedAuthModes, true)) {
            throw new InvalidArgumentException('Modo de autenticação inválido.');
        }

        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        if (!in_array($status, $this->allowedStatuses, true)) {
            throw new InvalidArgumentException('Status inválido.');
        }

        $warmup = strtolower(trim((string)($input['warmup_status'] ?? 'ready')));
        if (!in_array($warmup, $this->allowedWarmupStatuses, true)) {
            throw new InvalidArgumentException('Estado de aquecimento inválido.');
        }

        $hourlyLimit = max(1, (int)($input['hourly_limit'] ?? 1));
        $dailyLimit = max(1, (int)($input['daily_limit'] ?? 1));
        $burstLimit = max(1, (int)($input['burst_limit'] ?? 1));

        [$hourlyLimit, $dailyLimit, $burstLimit] = $this->applyProviderLimitDefaults(
            $provider,
            $hourlyLimit,
            $dailyLimit,
            $burstLimit,
            (string)($input['limit_source'] ?? '')
        );

        $credentials = $this->resolveCredentials($input, $existing);
        $this->assertAuthRequirements($authMode, $credentials);
        $headers = $this->normalizeKeyValuePayload($input['headers'] ?? null);
        $settings = $this->normalizeKeyValuePayload($input['settings'] ?? null);

        $resolvedDomain = $domain !== '' ? $domain : $this->deriveDomainFromEmail($fromEmail);

        if ($provider === 'custom') {
            $this->assertCustomDomainCaps($resolvedDomain, $hourlyLimit, $existing['id'] ?? null);
        }

        $imapSyncEnabled = array_key_exists('imap_sync_enabled', $input)
            ? ((bool)$input['imap_sync_enabled'] ? 1 : 0)
            : (int)($existing['imap_sync_enabled'] ?? 1);

        $payload = [
            'name' => $name,
            'provider' => $provider,
            'domain' => $this->nullIfEmpty($resolvedDomain),
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'reply_to' => $this->nullIfEmpty($input['reply_to'] ?? null),
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'encryption' => $encryption,
            'auth_mode' => $authMode,
            'credentials' => $this->encodeJson($credentials),
            'headers' => $headers,
            'settings' => $settings,
            'hourly_limit' => $hourlyLimit,
            'daily_limit' => $dailyLimit,
            'burst_limit' => $burstLimit,
            'warmup_status' => $warmup,
            'status' => $status,
            'notes' => $this->nullIfEmpty($input['notes'] ?? null),
            'imap_sync_enabled' => $imapSyncEnabled,
        ];

        if ($actorId !== null) {
            if ($existing === null) {
                $payload['created_by'] = $actorId;
            }
            $payload['updated_by'] = $actorId;
        }

        return $payload;
    }

    private function resolveCredentials(array $input, ?array $existing): array
    {
        $current = $existing !== null ? $this->decodeJson((string)$existing['credentials'], true) : [];

        $username = trim((string)($input['username'] ?? ($current['username'] ?? '')));

        return [
            'username' => $username !== '' ? $username : null,
            'password' => $this->encryptSecret($this->resolveSecret($input['password'] ?? null, $current['password'] ?? null)),
            'oauth_token' => $this->encryptSecret($this->resolveSecret($input['oauth_token'] ?? null, $current['oauth_token'] ?? null)),
            'api_key' => $this->encryptSecret($this->resolveSecret($input['api_key'] ?? null, $current['api_key'] ?? null)),
        ];
    }

    private function assertAuthRequirements(string $authMode, array $credentials): void
    {
        $username = trim((string)($credentials['username'] ?? ''));
        $password = trim((string)($credentials['password'] ?? ''));
        $oauthToken = trim((string)($credentials['oauth_token'] ?? ''));
        $apiKey = trim((string)($credentials['api_key'] ?? ''));

        if ($authMode === 'login') {
            if ($username === '' || $password === '') {
                throw new InvalidArgumentException('Usuário e senha são obrigatórios para autenticação tradicional.');
            }
        }

        if ($authMode === 'oauth2' && $oauthToken === '') {
            throw new InvalidArgumentException('Token OAuth2 é obrigatório para esse modo de autenticação.');
        }

        if ($authMode === 'apikey' && $apiKey === '') {
            throw new InvalidArgumentException('API key é obrigatória para esse modo de autenticação.');
        }
    }

    private function resolveSecret(mixed $provided, mixed $current): ?string
    {
        $value = $provided;
        if ($value === null || $value === '') {
            $value = $current ?? null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        $string = (string)$value;
        return trim($string) === '' ? null : $string;
    }

    private function deriveDomainFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        return isset($parts[1]) ? strtolower(trim($parts[1])) : 'custom-domain';
    }

    public function customCapSnapshot(string $domain, ?int $excludeId = null): array
    {
        $domainKey = $domain !== '' ? strtolower($domain) : 'custom-domain';
        $perDomainCap = max(1, $this->customDomainHourlyCap);
        $globalCap = max(1, $this->customGlobalHourlyCap);

        $domainSum = $this->repository->sumHourlyByDomain($domainKey, $excludeId);
        $globalSum = $this->repository->sumHourlyAllCustom($excludeId);

        return [
            'domain_cap_per_minute' => $perDomainCap,
            'domain_used_per_minute' => $domainSum,
            'domain_remaining_per_minute' => max(0, $perDomainCap - $domainSum),
            'global_cap_per_minute' => $globalCap,
            'global_used_per_minute' => $globalSum,
            'global_remaining_per_minute' => max(0, $globalCap - $globalSum),
        ];
    }

    private function assertCustomDomainCaps(string $domain, int $minuteLimit, ?int $excludeId = null): void
    {
        $domainKey = $domain !== '' ? strtolower($domain) : 'custom-domain';
        $perDomainCap = max(1, $this->customDomainHourlyCap);
        $globalCap = max(1, $this->customGlobalHourlyCap);

        $domainSum = $this->repository->sumHourlyByDomain($domainKey, $excludeId);
        if ($domainSum + $minuteLimit > $perDomainCap) {
            throw new InvalidArgumentException(sprintf(
                'Limite por minuto excede o teto do domínio (%d/min). Ajuste os valores das contas desse domínio.',
                $perDomainCap
            ));
        }

        $globalSum = $this->repository->sumHourlyAllCustom($excludeId);
        if ($globalSum + $minuteLimit > $globalCap) {
            throw new InvalidArgumentException(sprintf(
                'Limite por minuto excede o teto global de domínios próprios (%d/min).',
                $globalCap
            ));
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function applyProviderLimitDefaults(
        string $provider,
        int $hourlyLimit,
        int $dailyLimit,
        int $burstLimit,
        string $limitSource
    ): array {
        $limitSource = strtolower(trim($limitSource));
        if ($limitSource === 'manual') {
            return [$hourlyLimit, $dailyLimit, $burstLimit];
        }

        $defaults = EmailProviderLimitDefaults::for($provider);

        if ($hourlyLimit <= 0 && ($defaults['hourly_limit'] ?? 0) > 0) {
            $hourlyLimit = (int)$defaults['hourly_limit'];
        }
        if ($dailyLimit <= 0 && ($defaults['daily_limit'] ?? 0) > 0) {
            $dailyLimit = (int)$defaults['daily_limit'];
        }
        if ($burstLimit <= 0 && ($defaults['burst_limit'] ?? 0) > 0) {
            $burstLimit = (int)$defaults['burst_limit'];
        }

        return [$hourlyLimit, $dailyLimit, $burstLimit];
    }

    private function normalizePolicies(array $policies): array
    {
        $normalized = [];
        foreach ($policies as $policy) {
            $type = trim((string)($policy['policy_type'] ?? ''));
            $key = trim((string)($policy['policy_key'] ?? ''));
            if ($type === '' || $key === '') {
                continue;
            }

            $value = $policy['policy_value'] ?? null;
            if (is_array($value)) {
                $value = $this->encodeJson($value);
            } elseif ($value !== null) {
                $value = (string)$value;
            }

            $metadata = $policy['metadata'] ?? null;
            if (is_array($metadata)) {
                $metadata = $this->encodeJson($metadata);
            } elseif ($metadata !== null) {
                $metadata = (string)$metadata;
            }

            $normalized[] = [
                'policy_type' => $type,
                'policy_key' => $key,
                'policy_value' => $value,
                'metadata' => $metadata,
            ];
        }

        return $normalized;
    }

    private function normalizeKeyValuePayload(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $this->encodeJson($value);
        }

        return null;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        $string = trim((string)($value ?? ''));
        return $string === '' ? null : $string;
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(?string $payload, bool $decryptSecrets = false): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        if ($decryptSecrets) {
            foreach (['password', 'oauth_token', 'api_key'] as $field) {
                if (array_key_exists($field, $decoded)) {
                    $decoded[$field] = $this->decryptSecret($decoded[$field]);
                }
            }
        }

        return $decoded;
    }

    private function sanitizeCredentialsForOutput(array $credentials): array
    {
        $hasPassword = $this->hasSecret($credentials['password'] ?? null);
        $hasOauth = $this->hasSecret($credentials['oauth_token'] ?? null);
        $hasApiKey = $this->hasSecret($credentials['api_key'] ?? null);

        return [
            'username' => $credentials['username'] ?? null,
            'password' => null,
            'oauth_token' => null,
            'api_key' => null,
            'has_password' => $hasPassword,
            'has_oauth_token' => $hasOauth,
            'has_api_key' => $hasApiKey,
        ];
    }

    private function hasSecret(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function encryptSecret(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return 'enc:' . Crypto::encrypt($value);
        } catch (RuntimeException) {
            // Falha de criptografia não deve impedir gravação? Prefira armazenar nulo.
            return null;
        }
    }

    private function decryptSecret(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (!str_starts_with($value, 'enc:')) {
            return $value;
        }

        $payload = substr($value, 4);
        try {
            return Crypto::decrypt($payload);
        } catch (RuntimeException) {
            return null;
        }
    }
}
