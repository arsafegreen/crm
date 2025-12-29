<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\CertificateAccessRequestRepository;
use App\Repositories\UserRepository;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

final class CertificateAuthService
{
    public function __construct(
        private readonly CertificateParser $parser,
        private readonly UserRepository $users,
        private readonly CertificateAccessRequestRepository $requests
    ) {
    }

    public function authenticate(Request $request): CertificateAuthResult
    {
        $pem = $this->extractCertificatePem($request);
        if ($pem === null) {
            return new CertificateAuthResult('missing', null, null, null, 'Certificado digital obrigatório.');
        }

        try {
            $certificate = $this->parser->parse($pem);
        } catch (RuntimeException $exception) {
            return new CertificateAuthResult('invalid', null, null, null, $exception->getMessage());
        }

        if ($certificate->validFrom !== null && $certificate->validFrom > now()) {
            return new CertificateAuthResult('invalid', null, null, $certificate, 'Certificado ainda não válido para uso.');
        }

        if ($certificate->validTo !== null && $certificate->validTo < now()) {
            return new CertificateAuthResult('invalid', null, null, $certificate, 'Certificado expirado.');
        }

        $fingerprint = $certificate->fingerprint;
        $existingUser = $this->users->findByFingerprint($fingerprint);

        if ($existingUser !== null) {
            $status = (string)($existingUser['status'] ?? 'inactive');
            if ($status !== 'active') {
                return new CertificateAuthResult('denied', null, null, $certificate, 'Seu acesso foi desativado. Procure o administrador.');
            }

            $this->refreshUserCertificateData((int)$existingUser['id'], $certificate, $existingUser);
            $this->users->touchLastSeen((int)$existingUser['id']);

            $user = $this->mapUser($this->users->find((int)$existingUser['id']) ?? $existingUser);
            return new CertificateAuthResult('approved', $user, null, $certificate);
        }

        if ($this->isAdminFingerprint($fingerprint) || $this->shouldBootstrapAdmin()) {
            $user = $this->provisionAdmin($certificate);
            return new CertificateAuthResult('approved', $user, null, $certificate);
        }

        $accessRequest = $this->requests->upsertPending([
            'cpf' => $certificate->cpf,
            'name' => $certificate->commonName ?? $certificate->subject,
            'email' => null,
            'certificate_subject' => $certificate->subject,
            'certificate_fingerprint' => $fingerprint,
            'certificate_serial' => $certificate->serialNumber,
            'certificate_valid_to' => $certificate->validTo,
            'raw_certificate' => $certificate->rawPem,
        ]);

        return new CertificateAuthResult('pending', null, $accessRequest, $certificate, 'Acesso pendente de aprovação do administrador.');
    }

    private function extractCertificatePem(Request $request): ?string
    {
        $candidates = [
            (string)$request->server->get('SSL_CLIENT_CERT'),
            (string)$request->server->get('HTTP_SSL_CLIENT_CERT'),
            (string)$request->server->get('SSL_CLIENT_CERTIFICATE'),
        ];

        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        if (isset($_SERVER['SSL_CLIENT_CERT']) && trim((string)$_SERVER['SSL_CLIENT_CERT']) !== '') {
            return trim((string)$_SERVER['SSL_CLIENT_CERT']);
        }

        return null;
    }

    private function isAdminFingerprint(string $fingerprint): bool
    {
        $configured = env('ADMIN_CERT_FINGERPRINT', '');
        $list = env('ADMIN_CERT_FINGERPRINTS', '');

        $values = array_filter(array_map('trim', [$configured, ...explode(',', (string)$list)]));
        if ($values === []) {
            return false;
        }

        $fingerprint = strtoupper($fingerprint);
        foreach ($values as $value) {
            if ($value !== '' && strtoupper($value) === $fingerprint) {
                return true;
            }
        }
        return false;
    }

    private function shouldBootstrapAdmin(): bool
    {
        static $checked = false;
        static $cached = false;

        if ($checked) {
            return $cached;
        }

        $checked = true;

        $count = $this->users->activeAdminsCount();
        $cached = $count === 0;

        return $cached;
    }

    private function provisionAdmin(CertificateDetails $certificate): AuthenticatedUser
    {
        $existing = $this->users->findByFingerprint($certificate->fingerprint);
        $payload = [
            'cpf' => $certificate->cpf,
            'name' => $certificate->commonName ?? 'Administrador certificado',
            'email' => null,
            'role' => 'admin',
            'status' => 'active',
            'certificate_subject' => $certificate->subject,
            'certificate_serial' => $certificate->serialNumber,
            'certificate_valid_to' => $certificate->validTo,
            'approved_at' => now(),
            'approved_by' => 'system-auto',
        ];

        if ($existing === null) {
            $payload['certificate_fingerprint'] = $certificate->fingerprint;
            $id = $this->users->create($payload);
            $userRow = $this->users->find($id) ?? ($payload + ['id' => $id]);
        } else {
            $this->users->update((int)$existing['id'], $payload);
            $userRow = $this->users->find((int)$existing['id']) ?? $existing;
        }

        $storedRequest = $this->requests->findByFingerprint($certificate->fingerprint);
        if ($storedRequest !== null) {
            $this->requests->markApproved((int)$storedRequest['id'], 'system-auto');
        }

        $this->users->touchLastSeen((int)$userRow['id']);

        return $this->mapUser($userRow);
    }

    private function refreshUserCertificateData(int $userId, CertificateDetails $certificate, array $current): void
    {
        $updates = [];

        if (($current['certificate_subject'] ?? null) !== $certificate->subject) {
            $updates['certificate_subject'] = $certificate->subject;
        }
        if (($current['certificate_serial'] ?? null) !== $certificate->serialNumber) {
            $updates['certificate_serial'] = $certificate->serialNumber;
        }
        if (($current['certificate_valid_to'] ?? null) !== $certificate->validTo) {
            $updates['certificate_valid_to'] = $certificate->validTo;
        }
        if ($certificate->commonName !== null && ($current['name'] ?? null) !== $certificate->commonName) {
            $updates['name'] = $certificate->commonName;
        }
        if ($updates !== []) {
            $this->users->update($userId, $updates);
        }
    }

    private function mapUser(array $row): AuthenticatedUser
    {
        $cpf = isset($row['cpf']) ? digits_only((string)$row['cpf']) : null;
        if ($cpf !== null && $cpf === '') {
            $cpf = null;
        }
        $accessScope = ($row['client_access_scope'] ?? '') === 'custom' ? 'custom' : 'all';

        return new AuthenticatedUser(
            id: (int)$row['id'],
            name: (string)($row['name'] ?? 'Usuário'),
            email: (string)($row['email'] ?? ''),
            role: (string)($row['role'] ?? 'user'),
            fingerprint: (string)($row['certificate_fingerprint'] ?? ''),
            cpf: $cpf,
            permissions: $this->decodePermissions($row['permissions'] ?? '[]'),
            sessionIp: null,
            sessionLocation: null,
            sessionUserAgent: null,
            sessionStartedAt: null,
            lastSeenAt: isset($row['last_seen_at']) && is_numeric($row['last_seen_at']) ? (int)$row['last_seen_at'] : null,
            accessWindowStart: isset($row['access_allowed_from']) && is_numeric($row['access_allowed_from']) ? (int)$row['access_allowed_from'] : null,
            accessWindowEnd: isset($row['access_allowed_until']) && is_numeric($row['access_allowed_until']) ? (int)$row['access_allowed_until'] : null,
            requireKnownDevice: ((int)($row['require_known_device'] ?? 0)) === 1,
            clientAccessScope: $accessScope,
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

        $clean = [];
        foreach ($decoded as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $clean[$value] = true;
            }
        }

        $result = array_keys($clean);
        sort($result);

        return $result;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
