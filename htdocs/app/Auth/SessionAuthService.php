<?php

declare(strict_types=1);

namespace App\Auth;
use App\Security\CsrfTokenManager;
use App\Repositories\SettingRepository;
use App\Repositories\UserDeviceRepository;
use App\Repositories\UserRepository;
use App\Security\Totp;
use App\Services\Security\GeoIpService;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

final class SessionAuthService
{
    private UserRepository $users;
    private UserDeviceRepository $devices;
    private SettingRepository $settings;
    private Totp $totp;
    private GeoIpService $geoIp;
    private ?AuthenticatedUser $cachedUser = null;
    private ?array $securitySettingsCache = null;
    public const INACTIVITY_LIMIT = 1200;
    public const PASSWORD_MAX_AGE = 31104000; // 360 dias
    private const LAST_SEEN_REFRESH_INTERVAL = 120;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_DURATION = 7200;
    private const DEVICE_COOKIE = 'ms_device_id';
    private const CONTEXT_SESSION_KEY = 'auth_pending_login_context';

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->devices = new UserDeviceRepository();
        $this->settings = new SettingRepository();
        $this->totp = new Totp();
        $this->geoIp = new GeoIpService();
    }

    public function attempt(string $email, string $password, ?Request $request = null): AuthAttemptResult
    {
        unset($_SESSION[self::CONTEXT_SESSION_KEY]);
        $context = $this->buildLoginContext($request);

        $email = trim(strtolower($email));
        $user = $this->users->findByEmail($email);

        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return AuthAttemptResult::failure('Credenciais inválidas.');
        }

        $lockedUntilRaw = $user['locked_until'] ?? null;
        $lockedUntil = is_numeric($lockedUntilRaw) ? (int)$lockedUntilRaw : null;
        $now = now();

        if ($lockedUntil !== null) {
            if ($lockedUntil > $now) {
                $minutes = (int)ceil(($lockedUntil - $now) / 60);
                $message = sprintf('Muitas tentativas incorretas. Tente novamente em %d minuto(s) ou peça liberação ao administrador.', max($minutes, 1));
                return AuthAttemptResult::failure($message);
            }

            $this->users->clearLoginAttempts((int)$user['id']);
            $user['failed_login_attempts'] = 0;
            $user['locked_until'] = null;
        }

        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            $message = $this->registerFailedAttempt($user);
            return AuthAttemptResult::failure($message);
        }

        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $this->users->updatePassword((int)$user['id'], $newHash);
        }

        $this->users->clearLoginAttempts((int)$user['id']);

        $this->evaluatePasswordExpiry((int)$user['id'], (int)($user['password_updated_at'] ?? 0));

        $policyMessage = $this->enforceAccessPolicies($user, $context);
        if ($policyMessage !== null) {
            return AuthAttemptResult::failure($policyMessage);
        }

        if ((int)($user['totp_enabled'] ?? 0) === 1) {
            $_SESSION['auth_totp_user_id'] = (int)$user['id'];
            $this->storePendingLoginContext($context);
            return AuthAttemptResult::pendingTotp();
        }

        $this->finalizeLogin((int)$user['id'], $context);
        return AuthAttemptResult::success((int)$user['id']);
    }

    public function registerPendingUser(string $name, string $email, string $password): RegistrationResult
    {
        $name = trim($name);
        $email = trim(strtolower($email));
        $password = trim($password);

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return RegistrationResult::failure('Informe nome e e-mail válidos.');
        }

        $policyError = PasswordPolicy::validate($password, null, null);
        if ($policyError !== null) {
            return RegistrationResult::failure($policyError);
        }

        $existing = $this->users->findByEmail($email);
        $timestamp = now();
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $permissions = Permissions::defaultProfilePermissions();

        if ($existing !== null) {
            $status = (string)($existing['status'] ?? '');

            if ($status === 'pending') {
                return RegistrationResult::failure('Já existe uma solicitação aguardando aprovação para este e-mail.');
            }

            if ($status === 'active') {
                return RegistrationResult::failure('Este e-mail já possui acesso aprovado. Entre ou peça redefinição ao administrador.');
            }

            $this->users->update((int)$existing['id'], [
                'name' => $name,
                'email' => $email,
                'status' => 'pending',
                'password_hash' => $hash,
                'password_updated_at' => $timestamp,
                'approved_at' => null,
                'approved_by' => null,
                'certificate_fingerprint' => $this->makeFingerprint($email),
                'permissions' => $permissions,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'previous_password_hash' => null,
            ]);

            return RegistrationResult::success();
        }

        $this->users->create([
            'name' => $name,
            'email' => $email,
            'role' => 'user',
            'status' => 'pending',
            'certificate_fingerprint' => $this->makeFingerprint($email),
            'certificate_subject' => null,
            'certificate_serial' => null,
            'certificate_valid_to' => null,
            'password_hash' => $hash,
            'password_updated_at' => $timestamp,
            'previous_password_hash' => null,
            'last_seen_at' => null,
            'approved_at' => null,
            'approved_by' => null,
            'permissions' => $permissions,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        return RegistrationResult::success();
    }

    public function verifyTotp(Request $request, string $code): bool
    {
        $pendingId = $_SESSION['auth_totp_user_id'] ?? null;
        if (!is_int($pendingId) || $pendingId <= 0) {
            return false;
        }

        $user = $this->users->find($pendingId);
        if ($user === null || (int)($user['totp_enabled'] ?? 0) !== 1) {
            return false;
        }

        $secret = (string)($user['totp_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        if (!$this->totp->verify($secret, $code)) {
            return false;
        }

        unset($_SESSION['auth_totp_user_id']);
        $this->evaluatePasswordExpiry((int)$user['id'], (int)($user['password_updated_at'] ?? 0));

        $context = $this->consumePendingLoginContext();
        if ($context === null) {
            $context = $this->buildLoginContext($request);
        }

        $policyMessage = $this->enforceAccessPolicies($user, $context);
        if ($policyMessage !== null) {
            $_SESSION['auth_totp_error'] = $policyMessage;
            return false;
        }

        $this->finalizeLogin((int)$user['id'], $context);
        return true;
    }

    public function hasPendingTotp(): bool
    {
        return isset($_SESSION['auth_totp_user_id']) && is_int($_SESSION['auth_totp_user_id']);
    }

    public function beginTotpSetup(int $userId): TotpSetup
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new RuntimeException('Usuário não encontrado para configurar TOTP.');
        }

        $secret = $this->totp->generateSecret();
        $_SESSION['auth_totp_setup'] = [
            'user_id' => $userId,
            'secret' => $secret,
        ];

        $label = $user['email'] ?? ('usuario-' . $userId);
        $issuer = config('app.name', 'Marketing Suite');
        $uri = $this->totp->provisioningUri($secret, (string)$label, $issuer);

        return new TotpSetup($secret, $uri);
    }

    public function completeTotpSetup(string $code): bool
    {
        $data = $_SESSION['auth_totp_setup'] ?? null;
        if (!is_array($data) || !isset($data['user_id'], $data['secret'])) {
            return false;
        }

        $userId = (int)$data['user_id'];
        $secret = (string)$data['secret'];

        if (!$this->totp->verify($secret, $code)) {
            return false;
        }

        $this->users->updateTotp($userId, $secret, true, now());
        unset($_SESSION['auth_totp_setup']);

        return true;
    }

    public function logout(bool $preserveForced = false, ?int $forcedAt = null, ?string $notice = null): void
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        if (is_int($userId) && $userId > 0) {
            $this->users->updateSessionState($userId, null, $preserveForced ? $forcedAt : null);
        }

        unset(
            $_SESSION['auth_user_id'],
            $_SESSION['auth_totp_user_id'],
            $_SESSION['auth_totp_setup'],
            $_SESSION['auth_session_token'],
            $_SESSION['auth_last_seen_touch'],
            $_SESSION['auth_last_activity'],
            $_SESSION['auth_password_requires_change'],
            $_SESSION['auth_password_last_updated'],
            $_SESSION['profile_password_feedback'],
            $_SESSION[self::CONTEXT_SESSION_KEY]
        );
        session_regenerate_id(true);
        CsrfTokenManager::regenerate();
        $this->cachedUser = null;

        if ($notice !== null) {
            $_SESSION['auth_notice'] = $notice;
        }
    }

    public function defaultLandingUrl(?AuthenticatedUser $user = null): string
    {
        $user ??= $this->currentUser();

        if ($user === null) {
            return url('/');
        }

        if ($user->isAdmin() || $user->can('dashboard.overview')) {
            return url('/');
        }

        $targets = [
            'crm' => [
                'crm.overview',
                'crm.dashboard.metrics',
                'crm.dashboard.alerts',
                'crm.dashboard.performance',
                'crm.dashboard.partners',
                'crm.import',
            ],
            'crm/clients' => ['crm.clients'],
            'crm/partners' => ['crm.partners'],
            'crm/clients/off' => ['crm.off'],
            'campaigns/email' => ['campaigns.email'],
            'social-accounts' => ['social_accounts.manage'],
            'templates' => ['templates.library'],
            'config' => ['config.manage'],
        ];

        foreach ($targets as $path => $permissions) {
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    return url($path);
                }
            }
        }

        return url('/');
    }

    public function ensureAdminExists(): bool
    {
        return $this->users->activeAdminsCount() > 0;
    }

    public function finalizeLogin(int $userId, ?array $context = null): void
    {
        session_regenerate_id(true);
        CsrfTokenManager::regenerate();
        $_SESSION['auth_user_id'] = $userId;
        unset($_SESSION['auth_totp_setup'], $_SESSION['auth_totp_user_id']);
        unset($_SESSION[self::CONTEXT_SESSION_KEY]);
        $this->users->markLogin($userId);
        $this->users->touchLastSeen($userId);

        $token = bin2hex(random_bytes(32));
        $this->users->updateSessionState($userId, $token, null);
        $_SESSION['auth_session_token'] = $token;
        $_SESSION['auth_last_seen_touch'] = now();
        $_SESSION['auth_last_activity'] = now();

        if ($context !== null) {
            $ip = $this->stringOrNull($context['ip'] ?? null);
            $location = $this->stringOrNull($context['location'] ?? null);
            $userAgent = $this->stringOrNull($context['user_agent'] ?? null);

            $this->users->recordLoginMetadata($userId, $ip, $location, $userAgent);
            $this->users->updateSessionMetadata($userId, $ip, $location, $userAgent);

            $fingerprint = $this->stringOrNull($context['fingerprint'] ?? null);
            if ($fingerprint !== null) {
                $device = $this->devices->findByFingerprint($userId, $fingerprint);
                if ($device === null) {
                    $device = $this->devices->recordApprovedDevice($userId, $fingerprint, $userAgent, $ip, $location);
                } else {
                    if ($this->devices->isApproved($device)) {
                        $this->devices->markSeen((int)$device['id'], $userAgent, $ip, $location);
                    } else {
                        $this->devices->recordApprovedDevice($userId, $fingerprint, $userAgent, $ip, $location);
                    }
                }
            }

            $deviceId = $this->stringOrNull($context['device_id'] ?? null);
            if ($deviceId !== null) {
                $this->persistDeviceCookie($deviceId);
            }
        }

        $record = $this->users->find($userId);
        $updatedAt = isset($record['password_updated_at']) ? (int)$record['password_updated_at'] : null;
        $_SESSION['auth_password_last_updated'] = $updatedAt;
        $_SESSION['auth_password_requires_change'] = $this->isPasswordExpiredTimestamp($updatedAt);

        $this->cachedUser = null;
    }

    public function currentUser(): ?AuthenticatedUser
    {
        $id = $_SESSION['auth_user_id'] ?? null;
        if (!is_int($id) || $id <= 0) {
            return null;
        }

        if ($this->cachedUser !== null && $this->cachedUser->id === $id) {
            return $this->cachedUser;
        }

        $row = $this->users->find($id);
        if ($row === null) {
            return null;
        }

        if (($row['status'] ?? '') !== 'active') {
            $this->logout(false, null, 'Seu acesso foi desativado. Procure o administrador.');
            return null;
        }

        $passwordUpdatedAt = isset($row['password_updated_at']) && is_numeric($row['password_updated_at']) ? (int)$row['password_updated_at'] : null;
        $_SESSION['auth_password_last_updated'] = $passwordUpdatedAt;
        $_SESSION['auth_password_requires_change'] = $this->isPasswordExpiredTimestamp($passwordUpdatedAt);

        $sessionToken = isset($_SESSION['auth_session_token']) && is_string($_SESSION['auth_session_token']) && $_SESSION['auth_session_token'] !== ''
            ? (string)$_SESSION['auth_session_token']
            : null;
        $dbTokenRaw = $row['session_token'] ?? null;
        $dbToken = is_string($dbTokenRaw) && $dbTokenRaw !== '' ? (string)$dbTokenRaw : null;
        $forcedAtRaw = $row['session_forced_at'] ?? null;
        $forcedAt = is_numeric($forcedAtRaw) ? (int)$forcedAtRaw : null;

        if ($sessionToken === null && $dbToken === null) {
            $token = bin2hex(random_bytes(32));
            $this->users->updateSessionState($id, $token, null);
            $_SESSION['auth_session_token'] = $token;
            $_SESSION['auth_last_seen_touch'] = $_SESSION['auth_last_seen_touch'] ?? now();
            $sessionToken = $token;
            $dbToken = $token;
        }

        if ($sessionToken !== null) {
            if ($dbToken === null || $dbToken !== $sessionToken) {
                $this->logout(true, $forcedAt, $forcedAt !== null
                    ? 'Sua sessão foi finalizada pelo administrador. Faça login novamente.'
                    : 'Sua sessão expirou. Faça login novamente.'
                );
                return null;
            }
        } elseif ($dbToken !== null) {
            $this->logout(true, $forcedAt, 'Sua sessão expirou. Faça login novamente.');
            return null;
        }

        $now = now();
        $lastActivityRaw = $_SESSION['auth_last_activity'] ?? null;
        if ($lastActivityRaw === null) {
            $_SESSION['auth_last_activity'] = $now;
        } else {
            $lastActivity = is_numeric($lastActivityRaw) ? (int)$lastActivityRaw : 0;
            if ($lastActivity > 0 && ($now - $lastActivity) > self::INACTIVITY_LIMIT) {
                $this->logout(false, null, 'Sua sessão foi encerrada por inatividade. Faça login novamente.');
                return null;
            }
            $_SESSION['auth_last_activity'] = $now;
        }

        $windowMessage = $this->checkAccessWindow($row);
        if ($windowMessage !== null) {
            $this->logout(false, null, $windowMessage);
            return null;
        }

        $this->cachedUser = $this->mapUser($row);

        return $this->cachedUser;
    }

    public function refreshLastSeen(AuthenticatedUser $user): void
    {
        $lastTouch = $_SESSION['auth_last_seen_touch'] ?? 0;
        $now = now();

        if (!is_int($lastTouch) || $lastTouch <= 0 || ($now - $lastTouch) >= self::LAST_SEEN_REFRESH_INTERVAL) {
            $this->users->touchLastSeen($user->id);
            $_SESSION['auth_last_seen_touch'] = $now;
        }

        $_SESSION['auth_last_activity'] = $now;
    }

    public function inactivityExpiresAt(): ?int
    {
        $lastRaw = $_SESSION['auth_last_activity'] ?? null;
        if (!is_numeric($lastRaw)) {
            return null;
        }

        return (int)$lastRaw + self::INACTIVITY_LIMIT;
    }

    public function inactivityRemaining(): ?int
    {
        $expiresAt = $this->inactivityExpiresAt();
        if ($expiresAt === null) {
            return null;
        }

        $remaining = $expiresAt - now();
        return $remaining >= 0 ? $remaining : 0;
    }

    public function passwordRequiresChange(): bool
    {
        return !empty($_SESSION['auth_password_requires_change']);
    }

    public function clearPasswordChangeRequirement(): void
    {
        unset($_SESSION['auth_password_requires_change']);
        $_SESSION['auth_password_last_updated'] = now();
    }

    private function registerFailedAttempt(array $user): string
    {
        $attempts = (int)($user['failed_login_attempts'] ?? 0) + 1;
        $lockedUntil = null;

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $lockedUntil = now() + self::LOCK_DURATION;
        }

        $this->users->updateLoginAttempts((int)$user['id'], $attempts, $lockedUntil);

        if ($lockedUntil !== null) {
            return 'Muitas tentativas incorretas. A conta foi bloqueada por 2 horas. Aguarde ou solicite liberação ao administrador.';
        }

        $remaining = max(self::MAX_FAILED_ATTEMPTS - $attempts, 0);
        return $remaining > 0
            ? sprintf('Credenciais inválidas. Restam %d tentativa(s) antes do bloqueio.', $remaining)
            : 'Credenciais inválidas.';
    }

    private function evaluatePasswordExpiry(int $userId, int $updatedAt): void
    {
        $_SESSION['auth_password_last_updated'] = $updatedAt;
        $_SESSION['auth_password_requires_change'] = $this->isPasswordExpiredTimestamp($updatedAt);

        if (!empty($_SESSION['auth_password_requires_change'])) {
            $_SESSION['profile_password_feedback'] = $_SESSION['profile_password_feedback'] ?? [
                'type' => 'warning',
                'message' => 'Sua senha expirou. Atualize-a para continuar acessando os módulos da plataforma.',
            ];
        }
    }

    private function isPasswordExpiredTimestamp(?int $timestamp): bool
    {
        if ($timestamp === null || $timestamp <= 0) {
            return true;
        }

        return (now() - $timestamp) >= self::PASSWORD_MAX_AGE;
    }

    private function enforceAccessPolicies(array $user, ?array &$context): ?string
    {
        $windowMessage = $this->checkAccessWindow($user);
        if ($windowMessage !== null) {
            return $windowMessage;
        }

        $deviceMessage = $this->checkDeviceRestrictions($user, $context);
        if ($deviceMessage !== null) {
            return $deviceMessage;
        }

        return null;
    }

    private function checkAccessWindow(array $user): ?string
    {
        $start = $this->normalizeMinutes($user['access_allowed_from'] ?? null);
        $end = $this->normalizeMinutes($user['access_allowed_until'] ?? null);

        if ($start === null && $end === null) {
            $security = $this->securitySettings();
            $start = $security['access_start'];
            $end = $security['access_end'];

            if ($start === null && $end === null) {
                return null;
            }
        }

        $currentMinutes = $this->currentMinutesOfDay();
        if ($this->isWithinWindow($currentMinutes, $start, $end)) {
            return null;
        }

        if ($start === null) {
            return sprintf('Acesso permitido até %s. Volte nesse horário.', $this->formatWindowTime($end));
        }

        if ($end === null) {
            return sprintf('Acesso permitido a partir das %s. Volte nesse horário.', $this->formatWindowTime($start));
        }

        return sprintf(
            'Acesso permitido apenas entre %s e %s.',
            $this->formatWindowTime($start),
            $this->formatWindowTime($end)
        );
    }

    private function isWithinWindow(int $current, ?int $start, ?int $end): bool
    {
        if ($start === null && $end === null) {
            return true;
        }

        if ($start === null) {
            return $end !== null ? $current <= $end : true;
        }

        if ($end === null) {
            return $current >= $start;
        }

        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }

        return $current >= $start || $current <= $end;
    }

    private function formatWindowTime(?int $minutes): string
    {
        if ($minutes === null) {
            return '--:--';
        }

        $minutes = max(0, min(1439, $minutes));
        $hours = (int)floor($minutes / 60);
        $rest = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $rest);
    }

    private function currentMinutesOfDay(): int
    {
        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $now = (new DateTimeImmutable('@' . now()))->setTimezone($timezone);

        return ((int)$now->format('H') * 60) + (int)$now->format('i');
    }

    private function checkDeviceRestrictions(array $user, ?array &$context): ?string
    {
        $security = $this->securitySettings();
        $requireKnown = ((int)($user['require_known_device'] ?? 0)) === 1 || ($security['require_device'] ?? false);

        if ($context === null) {
            return $requireKnown
                ? 'Não foi possível identificar o dispositivo. O acesso foi bloqueado.'
                : null;
        }

        if (!isset($context['device_id']) || !is_string($context['device_id'])) {
            $generated = $this->generateDeviceId();
            $context['device_id'] = $generated;
            $context['fingerprint'] = $this->fingerprintFromDeviceId($generated);
            $this->persistDeviceCookie($generated);
        }

        $fingerprint = $this->stringOrNull($context['fingerprint'] ?? null);
        if ($fingerprint === null) {
            return $requireKnown
                ? 'Não foi possível registrar o dispositivo. Tente novamente mais tarde.'
                : null;
        }

        $userId = (int)$user['id'];
        $userAgent = $this->stringOrNull($context['user_agent'] ?? null);
        $ip = $this->stringOrNull($context['ip'] ?? null);
        $location = $this->stringOrNull($context['location'] ?? null);

        $device = $this->devices->findByFingerprint($userId, $fingerprint);

        if ($requireKnown) {
            if ($device === null) {
                $context['device_pending'] = true;
                $this->devices->recordPendingDevice($userId, $fingerprint, $userAgent, $ip, $location);
                return 'Este dispositivo ainda não está autorizado. Peça ao administrador para liberar o acesso.';
            }

            if (!$this->devices->isApproved($device)) {
                $this->devices->markSeen((int)$device['id'], $userAgent, $ip, $location);
                return 'Este dispositivo aguarda aprovação do administrador. Acesso bloqueado até a liberação.';
            }

            $this->devices->markSeen((int)$device['id'], $userAgent, $ip, $location);
            $context['device'] = $device;

            return null;
        }

        if ($device === null) {
            $device = $this->devices->recordApprovedDevice($userId, $fingerprint, $userAgent, $ip, $location);
        } else {
            if (!$this->devices->isApproved($device)) {
                $device = $this->devices->recordApprovedDevice($userId, $fingerprint, $userAgent, $ip, $location);
            } else {
                $this->devices->markSeen((int)$device['id'], $userAgent, $ip, $location);
            }
        }

        $context['device'] = $device;

        return null;
    }

    private function buildLoginContext(?Request $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $geo = $this->geoIp->lookupFromRequest($request);
        $ip = $this->stringOrNull($geo['ip'] ?? $request->getClientIp());
        $location = $this->stringOrNull($geo['label'] ?? null);
        $userAgent = $this->stringOrNull($request->headers->get('User-Agent'));
        $deviceId = $this->sanitizeDeviceId($request->cookies->get(self::DEVICE_COOKIE));

        $context = [
            'ip' => $ip,
            'location' => $location,
            'user_agent' => $userAgent,
            'device_id' => $deviceId,
        ];

        if ($deviceId !== null) {
            $context['fingerprint'] = $this->fingerprintFromDeviceId($deviceId);
        }

        return $context;
    }

    private function storePendingLoginContext(?array $context): void
    {
        if ($context === null) {
            unset($_SESSION[self::CONTEXT_SESSION_KEY]);
            return;
        }

        $_SESSION[self::CONTEXT_SESSION_KEY] = [
            'ip' => $this->stringOrNull($context['ip'] ?? null),
            'location' => $this->stringOrNull($context['location'] ?? null),
            'user_agent' => $this->stringOrNull($context['user_agent'] ?? null),
            'device_id' => $this->stringOrNull($context['device_id'] ?? null),
            'fingerprint' => $this->stringOrNull($context['fingerprint'] ?? null),
        ];
    }

    private function consumePendingLoginContext(): ?array
    {
        $stored = $_SESSION[self::CONTEXT_SESSION_KEY] ?? null;
        unset($_SESSION[self::CONTEXT_SESSION_KEY]);

        if (!is_array($stored)) {
            return null;
        }

        $context = [
            'ip' => $this->stringOrNull($stored['ip'] ?? null),
            'location' => $this->stringOrNull($stored['location'] ?? null),
            'user_agent' => $this->stringOrNull($stored['user_agent'] ?? null),
            'device_id' => $this->stringOrNull($stored['device_id'] ?? null),
            'fingerprint' => $this->stringOrNull($stored['fingerprint'] ?? null),
        ];

        if ($context['device_id'] !== null && $context['fingerprint'] === null) {
            $context['fingerprint'] = $this->fingerprintFromDeviceId($context['device_id']);
        }

        return $context;
    }

    private function generateDeviceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function fingerprintFromDeviceId(string $deviceId): string
    {
        return 'device:' . strtolower($deviceId);
    }

    private function persistDeviceCookie(string $deviceId): void
    {
        $deviceId = strtolower($deviceId);
        $current = $this->sanitizeDeviceId($_COOKIE[self::DEVICE_COOKIE] ?? null);
        if ($current === $deviceId) {
            return;
        }

        $expires = now() + 31536000;
        setcookie(self::DEVICE_COOKIE, $deviceId, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->shouldUseSecureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::DEVICE_COOKIE] = $deviceId;
    }

    private function shouldUseSecureCookies(): bool
    {
        if (config('app.force_https', false)) {
            return true;
        }

        $httpsFlag = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($httpsFlag === 'on' || $httpsFlag === '1') {
            return true;
        }

        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwardedProto === 'https';
    }

    private function sanitizeDeviceId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));
        if ($trimmed === '') {
            return null;
        }

        if (!preg_match('/^[a-f0-9]{16,64}$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    private function normalizeMinutes(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return max(0, min(1439, $value));
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (preg_match('/^(\d{1,2}):(\d{2})$/', $trimmed, $matches) === 1) {
                $hours = (int)$matches[1];
                $minutes = (int)$matches[2];
                $total = ($hours * 60) + $minutes;
                return max(0, min(1439, $total));
            }

            if (ctype_digit($trimmed)) {
                $numeric = (int)$trimmed;
                return max(0, min(1439, $numeric));
            }
        }

        return null;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function securitySettings(): array
    {
        if ($this->securitySettingsCache !== null) {
            return $this->securitySettingsCache;
        }

        $start = $this->normalizeMinutes($this->settings->get('security.access_start_minutes'));
        $end = $this->normalizeMinutes($this->settings->get('security.access_end_minutes'));
        $requireDevice = $this->normalizeBoolean($this->settings->get('security.require_known_device', false));

        $this->securitySettingsCache = [
            'access_start' => $start,
            'access_end' => $end,
            'require_device' => $requireDevice,
        ];

        return $this->securitySettingsCache;
    }

    private function mapUser(array $row): AuthenticatedUser
    {
        $fingerprint = trim((string)($row['certificate_fingerprint'] ?? ''));
        if ($fingerprint === '') {
            $email = trim(strtolower((string)($row['email'] ?? '')));
            $fingerprint = $email !== '' ? 'session:' . $email : 'session:user-' . (int)($row['id'] ?? 0);
        }

        $cpf = $row['cpf'] ?? null;
        $cpfValue = $cpf !== null ? (string)$cpf : null;

        $permissionsRaw = $row['permissions'] ?? '[]';
        $permissions = $this->decodePermissions($permissionsRaw);

        $sessionStartedAt = isset($row['session_started_at']) && is_numeric($row['session_started_at'])
            ? (int)$row['session_started_at']
            : null;
        $lastSeenAt = isset($row['last_seen_at']) && is_numeric($row['last_seen_at'])
            ? (int)$row['last_seen_at']
            : null;
        $clientAccessScope = $this->normalizeAccessScope($row['client_access_scope'] ?? 'all');

        return new AuthenticatedUser(
            id: (int)($row['id'] ?? 0),
            name: (string)($row['name'] ?? 'Usuário'),
            email: (string)($row['email'] ?? ''),
            role: (string)($row['role'] ?? 'user'),
            fingerprint: $fingerprint,
            cpf: $cpfValue,
            permissions: $permissions,
            sessionIp: $this->stringOrNull($row['session_ip'] ?? null),
            sessionLocation: $this->stringOrNull($row['session_location'] ?? null),
            sessionUserAgent: $this->stringOrNull($row['session_user_agent'] ?? null),
            sessionStartedAt: $sessionStartedAt,
            lastSeenAt: $lastSeenAt,
            accessWindowStart: isset($row['access_allowed_from']) && is_numeric($row['access_allowed_from']) ? (int)$row['access_allowed_from'] : null,
            accessWindowEnd: isset($row['access_allowed_until']) && is_numeric($row['access_allowed_until']) ? (int)$row['access_allowed_until'] : null,
            requireKnownDevice: ((int)($row['require_known_device'] ?? 0)) === 1,
            clientAccessScope: $clientAccessScope,
            allowOnlineClients: ((int)($row['allow_online_clients'] ?? 0)) === 1,
            allowInternalChat: ((int)($row['allow_internal_chat'] ?? 1)) === 1,
            allowExternalChat: ((int)($row['allow_external_chat'] ?? 1)) === 1,
            isAvp: ((int)($row['is_avp'] ?? 0)) === 1,
            avpIdentityLabel: $this->stringOrNull($row['avp_identity_label'] ?? null),
            avpIdentityCpf: $this->stringOrNull($row['avp_identity_cpf'] ?? null),
            chatIdentifier: $this->stringOrNull($row['chat_identifier'] ?? null),
            chatDisplayName: $this->stringOrNull($row['chat_display_name'] ?? null)
        );
    }

    private function normalizeAccessScope(mixed $value): string
    {
        return $value === 'custom' ? 'custom' : 'all';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function makeFingerprint(string $email): string
    {
        return 'login:' . hash('sha256', $email);
    }

    /**
     * @return string[]
     */
    private function decodePermissions(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = [];
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $item) {
            if (is_scalar($item) || $item === null) {
                $values[] = (string)$item;
            }
        }

        return Permissions::sanitize($values);
    }
}
