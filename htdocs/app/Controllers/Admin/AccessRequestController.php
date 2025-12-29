<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\AuthenticatedUser;
use App\Auth\PasswordPolicy;
use App\Auth\Permissions;
use App\Repositories\CertificateAccessRequestRepository;
use App\Repositories\CertificateRepository;
use App\Repositories\AvpAccessRepository;
use App\Repositories\ClientRepository;
use App\Repositories\UserDeviceRepository;
use App\Repositories\UserRepository;
use App\Repositories\SettingRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessRequestController
{
    private CertificateAccessRequestRepository $requests;
    private UserRepository $users;
    private UserDeviceRepository $devices;
    private SettingRepository $settings;
    private AvpAccessRepository $avpAccess;
    private ClientRepository $clients;
    private CertificateRepository $certificates;

    public function __construct()
    {
        $this->requests = new CertificateAccessRequestRepository();
        $this->users = new UserRepository();
        $this->devices = new UserDeviceRepository();
        $this->settings = new SettingRepository();
        $this->avpAccess = new AvpAccessRepository();
        $this->clients = new ClientRepository();
        $this->certificates = new CertificateRepository();
    }

    public function index(Request $request, array $vars = []): Response
    {
        $now = now();
        $pending = $this->requests->listPending();
        $recent = $this->requests->listRecentDecisions(10);
        $loginPending = array_map(function (array $user): array {
            $permissions = $this->decodePermissionsField($user['permissions'] ?? null);
            $user['permissions'] = $permissions === [] ? Permissions::defaultUserKeys() : $permissions;
            return $user;
        }, $this->users->listPendingRegistrations());
        $globalStart = $this->normalizeMinutes($this->settings->get('security.access_start_minutes'));
        $globalEnd = $this->normalizeMinutes($this->settings->get('security.access_end_minutes'));
        $globalLabel = $this->formatWindowLabel($globalStart, $globalEnd);
        if ($globalStart === null && $globalEnd === null) {
            $globalLabel = 'Sem restrição configurada';
        }

        $avpIdentityLookups = $this->collectIdentityLookups();

        $transformUser = function (array $user) use ($now): array {
            $user['permissions'] = $this->decodePermissionsField($user['permissions'] ?? null);
            $user['last_seen_at'] = isset($user['last_seen_at']) ? (int)$user['last_seen_at'] : null;
            $user['session_forced_at'] = isset($user['session_forced_at']) ? (int)$user['session_forced_at'] : null;
            $user['failed_login_attempts'] = isset($user['failed_login_attempts']) ? (int)$user['failed_login_attempts'] : 0;
            $user['locked_until'] = isset($user['locked_until']) ? (int)$user['locked_until'] : null;
            $user['is_online'] = $this->isOnline($user, $now);
            $user['session_ip'] = $this->stringOrNull($user['session_ip'] ?? null);
            $user['session_location'] = $this->stringOrNull($user['session_location'] ?? null);
            $user['session_user_agent'] = $this->stringOrNull($user['session_user_agent'] ?? null);
            $user['session_started_at'] = isset($user['session_started_at']) ? (int)$user['session_started_at'] : null;
            $user['session_started_label'] = $user['session_started_at'] ? format_datetime((int)$user['session_started_at'], 'd/m/Y H:i') : null;
            $startMinutes = isset($user['access_allowed_from']) ? $this->normalizeMinutes($user['access_allowed_from']) : null;
            $endMinutes = isset($user['access_allowed_until']) ? $this->normalizeMinutes($user['access_allowed_until']) : null;
            $user['access_allowed_from'] = $startMinutes;
            $user['access_allowed_until'] = $endMinutes;
            $user['access_window_start'] = $this->minutesToTimeString($startMinutes);
            $user['access_window_end'] = $this->minutesToTimeString($endMinutes);
            $user['access_window_label'] = $this->formatWindowLabel($startMinutes, $endMinutes);
            $user['require_known_device'] = ((int)($user['require_known_device'] ?? 0)) === 1;
            $user['is_admin'] = ((string)($user['role'] ?? 'user')) === 'admin';
            $user['client_access_scope'] = $this->normalizeAccessScope($user['client_access_scope'] ?? 'all');
            $user['allow_online_clients'] = ((int)($user['allow_online_clients'] ?? 0)) === 1;
            $user['allow_internal_chat'] = ((int)($user['allow_internal_chat'] ?? 1)) === 1;
            $user['allow_external_chat'] = ((int)($user['allow_external_chat'] ?? 1)) === 1;
            $user['chat_identifier'] = $this->stringOrNull($user['chat_identifier'] ?? null);

            $userId = isset($user['id']) ? (int)$user['id'] : 0;
            if ($userId > 0) {
                $devices = $this->devices->listForUser($userId);
                $normalizedDevices = $this->normalizeDevices($devices, $now);
                $user['devices'] = $normalizedDevices;
                $user['current_device'] = $this->resolveCurrentDevice(
                    $normalizedDevices,
                    $user['session_ip'] ?? null,
                    $user['session_location'] ?? null
                );
            } else {
                $user['devices'] = [];
                $user['current_device'] = null;
            }

            return $user;
        };

        $activeUsers = array_map($transformUser, $this->users->listActiveNonAdminUsers());
        foreach ($activeUsers as &$user) {
            $userId = isset($user['id']) ? (int)$user['id'] : 0;
            if ($userId <= 0) {
                $user['avp_filters'] = [];
                $user['avp_filters_count'] = 0;
                continue;
            }

            $user['avp_filters'] = $user['client_access_scope'] === 'custom'
                ? $this->avpAccess->listForUser($userId)
                : [];
            $user['avp_filters_count'] = count($user['avp_filters']);
        }
        unset($user);
        $adminUsers = array_map($transformUser, $this->users->listActiveAdmins());
        $disabledUsers = array_map(function (array $user): array {
            $user['permissions'] = $this->decodePermissionsField($user['permissions'] ?? null);
            $user['failed_login_attempts'] = isset($user['failed_login_attempts']) ? (int)$user['failed_login_attempts'] : 0;
            $user['locked_until'] = isset($user['locked_until']) ? (int)$user['locked_until'] : null;
            $user['client_access_scope'] = $this->normalizeAccessScope($user['client_access_scope'] ?? 'all');
            $user['allow_online_clients'] = ((int)($user['allow_online_clients'] ?? 0)) === 1;
            $user['allow_internal_chat'] = ((int)($user['allow_internal_chat'] ?? 1)) === 1;
            $user['allow_external_chat'] = ((int)($user['allow_external_chat'] ?? 1)) === 1;
            return $user;
        }, $this->users->listDisabledNonAdminUsers());

        $feedback = $_SESSION['admin_access_feedback'] ?? null;
        unset($_SESSION['admin_access_feedback']);

        $permissionLabels = $this->permissionLabelsForCollaborators();

        return view('admin/access_requests/index', [
            'pending' => $pending,
            'recent' => $recent,
            'loginPending' => $loginPending,
            'activeUsers' => $activeUsers,
            'disabledUsers' => $disabledUsers,
            'adminUsers' => $adminUsers,
            'permissionLabels' => $permissionLabels,
            'permissionGroups' => $this->permissionGroupsForCollaborators(),
            'feedback' => $feedback,
            'globalAccessWindow' => [
                'start' => $this->minutesToTimeString($globalStart),
                'end' => $this->minutesToTimeString($globalEnd),
                'label' => $globalLabel,
            ],
            'availableAvps' => $this->clients->listDistinctAvpNames(200),
            'avpIdentityLookups' => $avpIdentityLookups,
        ]);
    }

    public function updateAccessWindow(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores utilizam apenas a janela geral.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $requireKnown = (bool)$request->request->get('require_known_device', false);

        $clearWindow = (string)$request->request->get('clear_window', '') === '1';
        $startInput = null;
        $endInput = null;
        $startMinutes = null;
        $endMinutes = null;

        if (!$clearWindow) {
            $startInput = $this->stringOrNull($request->request->get('access_start'));
            $endInput = $this->stringOrNull($request->request->get('access_end'));

            $startMinutes = $this->parseTimeInput($startInput);
            $endMinutes = $this->parseTimeInput($endInput);

            if ($startInput !== null && $startMinutes === null) {
                $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Horário inicial inválido. Use o formato HH:MM.'];
                return new RedirectResponse(url('admin/access-requests'));
            }

            if ($endInput !== null && $endMinutes === null) {
                $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Horário final inválido. Use o formato HH:MM.'];
                return new RedirectResponse(url('admin/access-requests'));
            }

            if (($startMinutes === null) xor ($endMinutes === null)) {
                $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Informe os dois horários para criar uma janela personalizada.'];
                return new RedirectResponse(url('admin/access-requests'));
            }

            if ($startMinutes !== null && $endMinutes !== null && $startMinutes >= $endMinutes) {
                $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'O horário inicial deve ser menor que o horário final.'];
                return new RedirectResponse(url('admin/access-requests'));
            }
        }

        $this->users->updateAccessRestrictions($id, $startMinutes, $endMinutes, $requireKnown);

        $_SESSION['admin_access_feedback'] = ['type' => 'success', 'message' => 'Horário de acesso atualizado para o colaborador selecionado.'];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updateIdentity(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $cpf = $this->digitsOrNull($request->request->get('cpf'));

        if ($cpf !== null && strlen($cpf) !== 11) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'O CPF deve conter exatamente 11 dígitos.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($cpf !== null) {
            $existing = $this->users->findByCpf($cpf);
            if ($existing !== null && (int)($existing['id'] ?? 0) !== $id) {
                $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Este CPF já está vinculado a outro colaborador.'];
                return new RedirectResponse(url('admin/access-requests'));
            }
        }

        $this->users->update($id, ['cpf' => $cpf]);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => $cpf === null
                ? 'O CPF foi removido do cadastro deste colaborador.'
                : 'CPF atualizado com sucesso para este colaborador.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function lookupIdentity(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $query = $this->stringOrNull($request->request->get('lookup_query'));
        if ($query === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Informe um nome ou CPF para pesquisar na carteira.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $results = $this->clients->searchPeopleForAvpLink($query, 12);
        $this->rememberIdentityLookup($id, $query, $results);

        $_SESSION['admin_access_feedback'] = [
            'type' => $results === [] ? 'warning' : 'info',
            'message' => $results === []
                ? 'Nenhum cliente foi encontrado com o termo informado. Ajuste a busca e tente novamente.'
                : sprintf('%d correspondência(s) encontradas. Escolha abaixo qual deseja vincular.', count($results)),
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updateChatIdentifier(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $shouldClear = (string)$request->request->get('clear_identifier', '0') === '1';
        $identifier = $shouldClear ? null : $this->stringOrNull($request->request->get('chat_identifier'));

        if ($identifier !== null) {
            $normalized = preg_replace('/\s+/', ' ', mb_strtoupper($identifier, 'UTF-8'));
            $normalized = trim((string)$normalized);
            if ($normalized === '') {
                $identifier = null;
            } else {
                if (mb_strlen($normalized, 'UTF-8') > 32) {
                    $normalized = mb_substr($normalized, 0, 32, 'UTF-8');
                }
                $identifier = $normalized;
            }
        }

        $displayName = $this->stringOrNull($request->request->get('chat_display_name'));
        if ($displayName !== null) {
            $displayName = trim(preg_replace('/\s+/', ' ', (string)$displayName));
            if ($displayName === '') {
                $displayName = null;
            } elseif (mb_strlen($displayName, 'UTF-8') > 80) {
                $displayName = mb_substr($displayName, 0, 80, 'UTF-8');
            }
        }

        $this->users->update($id, [
            'chat_identifier' => $identifier,
            'chat_display_name' => $displayName,
        ]);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Identidade do chat atualizada. Prefixo e nome exibido já estão ativos.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function linkIdentityFromClient(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $clientId = (int)$request->request->get('client_id', 0);
        if ($clientId <= 0) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Selecione um registro válido da carteira para vincular.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $client = $this->clients->find($clientId);
        if ($client === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Não encontramos o cliente informado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $document = $this->digitsOrNull($client['document'] ?? null);
        if ($document !== null && strlen($document) !== 11) {
            $document = null;
        }

        if ($document !== null) {
            $existing = $this->users->findByCpf($document);
            if ($existing !== null && (int)($existing['id'] ?? 0) !== $id) {
                $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Este CPF já está vinculado a outro colaborador.'];
                return new RedirectResponse(url('admin/access-requests'));
            }
        }

        $name = $this->stringOrNull($client['name'] ?? ($client['titular_name'] ?? null));
        if ($name === null) {
            $name = $this->stringOrNull($record['name'] ?? null);
        }

        if ($document !== null) {
            $this->users->update($id, ['cpf' => $document]);
        }

        $this->users->updateAvpProfile($id, true, $name, $document);
        unset($_SESSION['avp_identity_lookup'][$id]);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Dados importados com sucesso. O colaborador foi identificado como AVP e já pode usar a própria agenda.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updateAdminDevicePolicy(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null || ($record['role'] ?? '') !== 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Administrador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $requireKnown = (string)$request->request->get('require_known_device', '0') === '1';
        $startMinutes = $this->normalizeMinutes($record['access_allowed_from'] ?? null);
        $endMinutes = $this->normalizeMinutes($record['access_allowed_until'] ?? null);

        $this->users->updateAccessRestrictions($id, $startMinutes, $endMinutes, $requireKnown);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => $requireKnown
                ? 'O administrador agora só acessa por dispositivos autorizados.'
                : 'O administrador pode acessar por qualquer dispositivo novamente.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function approve(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->requests->find($id);
        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Solicitação não encontrada.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['status'] ?? '') !== 'pending') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Esta solicitação já foi decidida anteriormente.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $admin = $this->currentUser($request);
        $reason = trim((string)$request->request->get('reason', '')) ?: null;

        $cpf = $record['cpf'] ?? null;
        $cpfDigits = $cpf !== null ? digits_only((string)$cpf) : null;
        if ($cpfDigits === '') {
            $cpfDigits = null;
        }

        $permissions = $this->ensurePermissionsOrDefault(
            $this->applyRoleRestrictions($this->sanitizePermissions($request), $record)
        );

        $payload = [
            'name' => (string)($record['name'] ?? 'Usuário certificado'),
            'email' => null,
            'role' => 'user',
            'status' => 'active',
            'certificate_fingerprint' => (string)$record['certificate_fingerprint'],
            'certificate_subject' => (string)($record['certificate_subject'] ?? ''),
            'certificate_serial' => $record['certificate_serial'] ?? null,
            'certificate_valid_to' => $record['certificate_valid_to'] ?? null,
            'approved_at' => now(),
            'approved_by' => $admin?->fingerprint ?? 'admin',
            'permissions' => $permissions,
        ];

        if ($cpfDigits !== null) {
            $payload['cpf'] = $cpfDigits;
        }

        $userId = null;
        if ($cpfDigits !== null) {
            $existingByCpf = $this->users->findByCpf($cpfDigits);
            if ($existingByCpf !== null) {
                $this->users->update((int)$existingByCpf['id'], $payload);
                $userId = (int)$existingByCpf['id'];
            }
        }

        if ($userId === null) {
            $existingByFingerprint = $this->users->findByFingerprint((string)$record['certificate_fingerprint']);
            if ($existingByFingerprint !== null) {
                $this->users->update((int)$existingByFingerprint['id'], $payload);
                $userId = (int)$existingByFingerprint['id'];
            }
        }

        if ($userId === null) {
            $userId = $this->users->create($payload);
        }

        $this->users->touchLastSeen($userId);
        $this->requests->markApproved($id, $admin?->fingerprint ?? 'admin', $reason);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Acesso liberado com sucesso para o certificado informado.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function deny(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->requests->find($id);
        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Solicitação não encontrada.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['status'] ?? '') !== 'pending') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Esta solicitação já foi decidida anteriormente.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $admin = $this->currentUser($request);
        $reason = trim((string)$request->request->get('reason', '')) ?: null;

        $this->requests->markDenied($id, $admin?->fingerprint ?? 'admin', $reason);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Acesso recusado e certificado mantido sem permissão.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function approveLogin(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null || ($record['status'] ?? '') !== 'pending') {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Solicitação de login não encontrada ou já processada.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $admin = $this->currentUser($request);
        $fingerprint = $admin?->fingerprint ?? 'admin';
        $permissions = $this->ensurePermissionsOrDefault($this->sanitizePermissions($request));
        $now = now();

        $this->users->update($id, [
            'status' => 'active',
            'approved_at' => $now,
            'approved_by' => $fingerprint,
            'permissions' => $permissions,
        ]);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Login liberado com sucesso. Informe o colaborador para acessar com e-mail e senha registrados.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function denyLogin(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null || ($record['status'] ?? '') !== 'pending') {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Solicitação de login não encontrada ou já processada.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $admin = $this->currentUser($request);
        $fingerprint = $admin?->fingerprint ?? 'admin';

        $this->users->denyRegistration($id, $fingerprint);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Solicitação de login negada. Caso necessário, o colaborador poderá reenviar um novo pedido.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updatePermissions(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Permissões de administradores não podem ser alteradas por esta interface.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $permissions = $this->ensurePermissionsOrDefault(
            $this->applyRoleRestrictions($this->sanitizePermissions($request), $record)
        );
        $this->users->updatePermissions($id, $permissions);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Permissões atualizadas com sucesso para o colaborador selecionado.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function forceLogout(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Sessões de administradores não podem ser encerradas por esta interface.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $this->users->forceLogout($id);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Sessão encerrada e tentativas liberadas. O colaborador precisará entrar novamente com login e senha.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function resetPassword(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Use o próprio menu de perfil para alterar a senha do administrador.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $password = (string)$request->request->get('password', '');
        $confirmation = (string)$request->request->get('password_confirmation', '');

        if ($password === '' || $confirmation === '') {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Informe a nova senha e a confirmação.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($password !== $confirmation) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'As senhas informadas não conferem.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $currentHash = (string)($record['password_hash'] ?? '');
        $previousHash = (string)($record['previous_password_hash'] ?? '');
        $policyError = PasswordPolicy::validate($password, $currentHash, $previousHash);
        if ($policyError !== null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => $policyError];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $this->users->updatePassword($id, $hash);
        $this->users->forceLogout($id);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Senha redefinida com sucesso. O colaborador precisará entrar novamente com a nova senha.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function deactivate(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores não podem ser desativados por esta interface.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['status'] ?? '') === 'disabled') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Este colaborador já está desativado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $this->users->deactivate($id);
        $this->users->resetLockout($id);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Colaborador desativado. Ele não poderá acessar até ser reativado.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function activate(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores já possuem acesso completo.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['status'] ?? '') === 'active') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Este colaborador já está ativo.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $this->users->activate($id);
        $this->users->resetLockout($id);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Colaborador reativado. Ele poderá acessar novamente com as credenciais vigentes.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function delete(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores não podem ser excluídos por esta interface.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $this->users->delete($id);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'Colaborador removido permanentemente.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updateClientAccessScope(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores já possuem acesso total à carteira.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $scope = $this->normalizeAccessScope($request->request->get('scope'));
        $this->users->updateClientAccessScope($id, $scope);

        $message = $scope === 'custom'
            ? 'O colaborador agora verá somente clientes cujo último AVP esteja liberado abaixo.'
            : 'O colaborador voltou a enxergar toda a carteira.';

        if ($scope === 'custom') {
            $seedResult = $this->seedPrimaryAvpFilters($record, $this->currentUser($request)?->id);
            $seeded = $seedResult['inserted'];
            $onlineEnabled = $seedResult['onlineEnabled'];

            if ($seeded > 0) {
                $message .= ' ' . sprintf('%d AVP(s) foram liberados automaticamente.', $seeded);
            } elseif ($onlineEnabled) {
                $message .= ' Nenhum AVP foi identificado automaticamente, então liberamos clientes sem AVP (modo on-line).';
            } else {
                $message .= ' Nenhum AVP foi identificado automaticamente. Utilize "Liberar AVPs atendidos" ou adicione manualmente.';
            }
        }

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => $message,
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updateAllowOnlineClients(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores já possuem acesso completo.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($this->normalizeAccessScope($record['client_access_scope'] ?? 'all') !== 'custom') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Ative "Somente AVPs liberados" antes de alterar o modo on-line.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $allowOnline = (string)$request->request->get('allow_online_clients', '0') === '1';
        $this->users->updateAllowOnlineClients($id, $allowOnline);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => $allowOnline
                ? 'Clientes sem AVP foram liberados (modo on-line).'
                : 'Clientes sem AVP voltaram a ficar bloqueados para este colaborador.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updateChatPermissions(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $allowInternal = (string)$request->request->get('allow_internal_chat', '0') === '1';
        $allowExternal = (string)$request->request->get('allow_external_chat', '0') === '1';

        $this->users->updateChatPermissions($id, $allowInternal, $allowExternal);

        $messageParts = [
            $allowInternal ? 'Chat interno liberado' : 'Chat interno bloqueado',
            $allowExternal ? 'Atendimento externo liberado' : 'Atendimento externo bloqueado',
        ];

        $_SESSION['admin_access_feedback'] = [
            'type' => ($allowInternal || $allowExternal) ? 'success' : 'warning',
            'message' => implode(' · ', $messageParts),
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function updateAvpProfile(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $isAdmin = ((string)($record['role'] ?? 'user')) === 'admin';
        $wantsAvpProfile = (string)$request->request->get('is_avp', '0') === '1' && !$isAdmin;
        $label = $this->stringOrNull($request->request->get('avp_identity_label'));
        $cpf = $this->digitsOrNull($request->request->get('avp_identity_cpf'));

        if ($cpf !== null && strlen($cpf) !== 11) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'CPF do AVP deve ter 11 dígitos.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($wantsAvpProfile && $label === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Informe o nome que identificará este AVP.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($isAdmin) {
            $wantsAvpProfile = false; // administradores não possuem agenda própria
        }

        $this->users->updateAvpProfile($id, $wantsAvpProfile, $label, $cpf);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => $wantsAvpProfile
                ? 'Este colaborador agora é tratado como AVP e pode configurar a própria agenda.'
                : 'Perfil de AVP removido. O usuário pode agendar para outros, mas não terá agenda própria.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function grantClientAccess(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores já possuem liberação total da carteira.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($this->normalizeAccessScope($record['client_access_scope'] ?? 'all') !== 'custom') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Defina a carteira como "Somente AVPs liberados" antes de adicionar filtros.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $payload = $request->request->all();
        $rawSelections = $payload['avp_labels'] ?? [];
        $selectedLabels = [];

        if (is_array($rawSelections)) {
            foreach ($rawSelections as $value) {
                $labelValue = $this->stringOrNull($value);
                if ($labelValue !== null) {
                    $selectedLabels[] = $labelValue;
                }
            }
        }

        $selectedLabels = array_values(array_unique($selectedLabels));
        if (count($selectedLabels) > 200) {
            $selectedLabels = array_slice($selectedLabels, 0, 200);
        }

        $customLabel = $this->stringOrNull($request->request->get('custom_avp_label'));
        $customCpf = $this->digitsOrNull($request->request->get('custom_avp_cpf'));

        if ($customCpf !== null && strlen($customCpf) !== 11) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'CPF do AVP deve conter 11 dígitos.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($customCpf !== null && $customLabel === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Informe o nome do AVP para vincular ao CPF informado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($selectedLabels === [] && $customLabel === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Selecione ao menos um AVP ou informe manualmente no campo "Outro AVP".'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $admin = $this->currentUser($request);
        $inserted = 0;
        $updated = 0;

        foreach ($selectedLabels as $selectedLabel) {
            $result = $this->avpAccess->addAvp($id, $selectedLabel, null, $admin?->id);
            if ($result) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        if ($customLabel !== null) {
            $result = $this->avpAccess->addAvp($id, $customLabel, $customCpf, $admin?->id);
            if ($result) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        $displayName = (string)($record['name'] ?? 'o colaborador');
        if ($inserted > 0) {
            $message = sprintf('%d AVP(s) foram liberados para %s.', $inserted, $displayName);
            if ($updated > 0) {
                $message .= ' Os demais já estavam liberados e foram mantidos.';
            }
            $type = 'success';
        } elseif ($updated > 0) {
            $message = 'Os AVPs selecionados já estavam liberados para este colaborador.';
            $type = 'success';
        } else {
            $message = 'Nenhum AVP pôde ser processado. Verifique os dados e tente novamente.';
            $type = 'error';
        }

        $_SESSION['admin_access_feedback'] = [
            'type' => $type,
            'message' => $message,
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function syncClientAccessFromHistory(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $record = $this->users->find($id);

        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if (($record['role'] ?? '') === 'admin') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Administradores já possuem liberação total da carteira.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        if ($this->normalizeAccessScope($record['client_access_scope'] ?? 'all') !== 'custom') {
            $_SESSION['admin_access_feedback'] = ['type' => 'warning', 'message' => 'Defina a carteira como "Somente clientes liberados" antes de sincronizar os atendimentos.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $admin = $this->currentUser($request);
        $names = $this->resolveAvpNamesFromHistory($record);
        $inserted = $names === [] ? 0 : $this->avpAccess->addMany($id, $names, $admin?->id);

        if ($names === []) {
            $onlineEnabled = $this->enableOnlineFallback($record);
            if ($onlineEnabled) {
                $type = 'success';
                $message = 'Nenhum AVP compatível foi identificado, então habilitamos o modo on-line para clientes sem AVP.';
            } else {
                $type = 'warning';
                $message = 'Nenhum AVP compatível foi identificado para sincronizar. Revise o CPF/nome cadastrado.';
            }
        } elseif ($inserted === 0) {
            $type = 'success';
            $message = 'Os AVPs atendidos já estavam liberados previamente.';
        } else {
            $type = 'success';
            $message = sprintf('%d AVP(s) foram liberados automaticamente.', $inserted);
        }

        $_SESSION['admin_access_feedback'] = [
            'type' => $type,
            'message' => $message,
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    public function revokeClientAccess(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $filterId = (int)($vars['filterId'] ?? ($vars['clientId'] ?? 0));

        if ($filterId <= 0) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Filtro de AVP inválido para remoção.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $record = $this->users->find($id);
        if ($record === null) {
            $_SESSION['admin_access_feedback'] = ['type' => 'error', 'message' => 'Colaborador não encontrado.'];
            return new RedirectResponse(url('admin/access-requests'));
        }

        $this->avpAccess->remove($id, $filterId);

        $_SESSION['admin_access_feedback'] = [
            'type' => 'success',
            'message' => 'AVP removido da carteira liberada do colaborador.',
        ];

        return new RedirectResponse(url('admin/access-requests'));
    }

    private function currentUser(Request $request): ?AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        return $user instanceof AuthenticatedUser ? $user : null;
    }

    /**
     * @return string[]
     */
    private function sanitizePermissions(Request $request): array
    {
        $payload = $request->request->all();
        $raw = $payload['permissions'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $value) {
            $values[] = (string)$value;
        }

        return Permissions::sanitize($values);
    }

    /**
     * @return array<string, string>
     */
    private function permissionLabelsForCollaborators(): array
    {
        $labels = Permissions::MODULES;
        unset($labels['admin.access']);
        return $labels;
    }

    /**
     * @return array<int, array{title:string, subtitle?:string, keys:array<int, string>}>
     */
    private function permissionGroupsForCollaborators(): array
    {
        return [
            [
                'title' => 'Menu Dashboard',
                'subtitle' => 'Painel principal e automação',
                'keys' => [
                    'dashboard.overview',
                    'automation.control',
                ],
            ],
            [
                'title' => 'Menu Clientes',
                'subtitle' => 'CRM e gestão da carteira',
                'keys' => [
                    'crm.overview',
                    'crm.dashboard.metrics',
                    'crm.dashboard.alerts',
                    'crm.dashboard.performance',
                    'crm.dashboard.partners',
                    'crm.import',
                    'crm.clients',
                    'crm.partners',
                    'crm.off',
                ],
            ],
            [
                'title' => 'Menu Marketing',
                'subtitle' => 'Campanhas e presença digital',
                'keys' => [
                    'campaigns.email',
                    'social_accounts.manage',
                    'templates.library',
                ],
            ],
            [
                'title' => 'Menu Configurações',
                'subtitle' => 'Parâmetros e integrações',
                'keys' => [
                    'config.manage',
                ],
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function decodePermissionsField($value): array
    {
        if (is_array($value)) {
            return Permissions::sanitize($value);
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return Permissions::sanitize($decoded);
            }
        }

        return [];
    }

    /**
     * @param array<string> $permissions
     * @return string[]
     */
    private function applyRoleRestrictions(array $permissions, array $record): array
    {
        $role = (string)($record['role'] ?? 'user');
        if ($role !== 'admin') {
            $permissions = array_values(array_diff($permissions, ['admin.access']));
        }

        return Permissions::sanitize($permissions);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return array{inserted:int, onlineEnabled:bool}
     */
    private function seedPrimaryAvpFilters(array &$userRecord, ?int $grantedBy): array
    {
        $result = ['inserted' => 0, 'onlineEnabled' => false];

        $userId = (int)($userRecord['id'] ?? 0);
        if ($userId <= 0) {
            return $result;
        }

        $names = $this->resolveAvpNamesFromHistory($userRecord);
        if ($names === []) {
            $result['onlineEnabled'] = $this->enableOnlineFallback($userRecord);
            return $result;
        }

        $inserted = 0;
        foreach ($names as $label) {
            if ($this->avpAccess->addAvp($userId, $label, null, $grantedBy)) {
                $inserted++;
            }
        }

        $result['inserted'] = $inserted;
        return $result;
    }

    /**
     * @return string[]
     */
    private function resolveAvpNamesFromHistory(array $userRecord): array
    {
        $names = [];

        $userId = (int)($userRecord['id'] ?? 0);
        if ($userId > 0) {
            $names = array_merge($names, $this->clients->listAvpNamesByAssignedUser($userId));
        }

        $cpf = $this->digitsOrNull($userRecord['cpf'] ?? null);
        if ($cpf !== null) {
            $names = array_merge($names, $this->certificates->listAvpNamesByCpf($cpf));
        }

        $selfName = $this->stringOrNull($userRecord['name'] ?? null);
        if ($selfName !== null) {
            $names[] = $selfName;
        }

        $names = array_map(static fn(string $value): string => trim(preg_replace('/\s+/', ' ', $value) ?? ''), $names);
        $names = array_filter($names, static fn(string $value): bool => $value !== '');

        return array_values(array_unique($names));
    }

    private function enableOnlineFallback(array &$userRecord): bool
    {
        $userId = (int)($userRecord['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $alreadyAllowed = ((int)($userRecord['allow_online_clients'] ?? 0)) === 1;
        if ($alreadyAllowed) {
            return false;
        }

        $this->users->updateAllowOnlineClients($userId, true);
        $userRecord['allow_online_clients'] = 1;

        return true;
    }

    private function rememberIdentityLookup(int $userId, string $query, array $results): void
    {
        if ($userId <= 0) {
            return;
        }

        $_SESSION['avp_identity_lookup'] ??= [];
        $_SESSION['avp_identity_lookup'][$userId] = [
            'query' => $query,
            'results' => $results,
            'generated_at' => now(),
        ];
    }

    private function collectIdentityLookups(): array
    {
        $store = isset($_SESSION['avp_identity_lookup']) && is_array($_SESSION['avp_identity_lookup'])
            ? $_SESSION['avp_identity_lookup']
            : [];

        $now = now();
        $normalized = [];

        foreach ($store as $key => $payload) {
            $userId = (int)$key;
            if ($userId <= 0 || !is_array($payload)) {
                unset($store[$key]);
                continue;
            }

            $generatedAt = isset($payload['generated_at']) ? (int)$payload['generated_at'] : 0;
            if ($generatedAt !== 0 && $generatedAt < $now - 600) {
                unset($store[$key]);
                continue;
            }

            $results = [];
            if (isset($payload['results']) && is_array($payload['results'])) {
                foreach ($payload['results'] as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }

                    $candidateId = (int)($candidate['id'] ?? 0);
                    if ($candidateId <= 0) {
                        continue;
                    }

                    $results[] = [
                        'id' => $candidateId,
                        'name' => (string)($candidate['name'] ?? ''),
                        'document' => isset($candidate['document']) ? (string)$candidate['document'] : null,
                        'status' => isset($candidate['status']) ? (string)$candidate['status'] : null,
                        'last_avp_name' => isset($candidate['last_avp_name']) ? (string)$candidate['last_avp_name'] : null,
                    ];
                }
            }

            $normalized[$userId] = [
                'query' => (string)($payload['query'] ?? ''),
                'results' => $results,
            ];
        }

        $_SESSION['avp_identity_lookup'] = $store;

        return $normalized;
    }

    private function digitsOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $digits = digits_only((string)$value);
        return $digits !== '' ? $digits : null;
    }

    private function normalizeAccessScope(mixed $value): string
    {
        return $value === 'custom' ? 'custom' : 'all';
    }

    private function normalizeMinutes($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int)$value;

        if ($intValue < 0) {
            return 0;
        }

        $maxMinutes = (24 * 60) - 1;
        if ($intValue > $maxMinutes) {
            return $maxMinutes;
        }

        return $intValue;
    }

    private function minutesToTimeString(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        $maxMinutes = (24 * 60) - 1;
        $minutes = max(0, min($maxMinutes, $minutes));
        $hours = (int)floor($minutes / 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    private function formatWindowLabel(?int $fromMinutes, ?int $untilMinutes): string
    {
        if ($fromMinutes === null && $untilMinutes === null) {
            return 'Segue horário padrão';
        }

        $start = $this->minutesToTimeString($fromMinutes);
        $end = $this->minutesToTimeString($untilMinutes);

        if ($start === '' || $end === '') {
            return 'Janela personalizada incompleta';
        }

        return $start . ' – ' . $end;
    }

    private function parseTimeInput(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (!preg_match('/^(\d{2}):(\d{2})$/', trim($value), $matches)) {
            return null;
        }

        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDevices(array $devices, int $now): array
    {
        $normalized = [];

        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }

            $lastSeen = isset($device['last_seen_at']) ? (int)$device['last_seen_at'] : null;
            $approvedAt = isset($device['approved_at']) ? (int)$device['approved_at'] : null;
            $isApproved = $approvedAt !== null && $approvedAt > 0;

            $normalized[] = [
                'id' => isset($device['id']) ? (int)$device['id'] : 0,
                'fingerprint' => (string)($device['fingerprint'] ?? ''),
                'label' => $this->stringOrNull($device['label'] ?? null),
                'user_agent' => $this->stringOrNull($device['user_agent'] ?? null),
                'last_seen_at' => $lastSeen,
                'last_seen_at_label' => $lastSeen !== null ? format_datetime($lastSeen, 'd/m/Y H:i') : 'Nunca registrado',
                'last_ip' => $this->stringOrNull($device['last_ip'] ?? null),
                'last_location' => $this->stringOrNull($device['last_location'] ?? null),
                'approved_at' => $approvedAt,
                'approved_at_label' => $approvedAt !== null ? format_datetime($approvedAt, 'd/m/Y H:i') : null,
                'approved_by' => $this->stringOrNull($device['approved_by'] ?? null),
                'is_recent' => $lastSeen !== null && ($now - $lastSeen) <= 900,
                'is_approved' => $isApproved,
                'status_label' => $isApproved ? 'Autorizado' : 'Pendente',
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     */
    private function resolveCurrentDevice(array $devices, ?string $currentIp, ?string $currentLocation): ?array
    {
        foreach ($devices as $device) {
            if ($currentIp !== null && $device['last_ip'] === $currentIp) {
                return $device;
            }
        }

        foreach ($devices as $device) {
            if (!empty($device['is_recent'])) {
                return $device;
            }
        }

        return $devices[0] ?? null;
    }

    private function isOnline(array $user, int $now): bool
    {
        $lastSeen = isset($user['last_seen_at']) ? (int)$user['last_seen_at'] : null;
        $token = $user['session_token'] ?? null;

        if ($lastSeen === null || $lastSeen <= 0) {
            return false;
        }

        if (!is_string($token) || $token === '') {
            return false;
        }

        return ($now - $lastSeen) <= 300;
    }

    private function ensurePermissionsOrDefault(array $permissions): array
    {
        return $permissions === [] ? Permissions::defaultProfilePermissions() : $permissions;
    }
}
