<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuthService;
use App\Auth\AuthenticatedUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController
{
    private SessionAuthService $auth;

    public function __construct()
    {
        $this->auth = new SessionAuthService();
    }

    public function loginForm(Request $request): Response
    {
        if ($this->auth->currentUser() !== null) {
            return new RedirectResponse(url('/'));
        }

        if ($this->auth->hasPendingTotp()) {
            return new RedirectResponse(url('auth/totp'));
        }

        $error = $_SESSION['auth_error'] ?? null;
        $notice = $_SESSION['auth_notice'] ?? null;
        $email = $_SESSION['auth_email'] ?? '';

        unset($_SESSION['auth_error'], $_SESSION['auth_notice'], $_SESSION['auth_email']);

        return view('auth/login', [
            '_layout' => 'layouts/auth',
            'error' => $error,
            'email' => $email,
            'notice' => $notice,
        ]);
    }

    public function login(Request $request): Response
    {
        $email = (string)$request->request->get('email', '');
        $password = (string)$request->request->get('password', '');

        $result = $this->auth->attempt($email, $password, $request);

        if ($result->success && $result->userId !== null) {
            $target = $_SESSION['auth_intended'] ?? null;
            unset($_SESSION['auth_intended']);

            $user = $this->auth->currentUser();
            $landing = $this->resolveLandingTarget($user, $target);

            return new RedirectResponse($landing);
        }

        if ($result->requiresTotp) {
            return new RedirectResponse(url('auth/totp'));
        }

        $_SESSION['auth_error'] = $result->message ?? 'Credenciais inválidas.';
        $_SESSION['auth_email'] = trim(strtolower($email));

        return new RedirectResponse(url('auth/login'));
    }

    public function totpForm(Request $request): Response
    {
        if (!$this->auth->hasPendingTotp()) {
            return new RedirectResponse(url('auth/login'));
        }

        $error = $_SESSION['auth_totp_error'] ?? null;
        unset($_SESSION['auth_totp_error']);

        return view('auth/totp', [
            '_layout' => 'layouts/auth',
            'error' => $error,
        ]);
    }

    public function totp(Request $request): Response
    {
        $code = preg_replace('/\D+/', '', (string)$request->request->get('code', '')) ?? '';

        if ($code !== '' && $this->auth->verifyTotp($request, $code)) {
            $target = $_SESSION['auth_intended'] ?? null;
            unset($_SESSION['auth_intended']);

            $user = $this->auth->currentUser();
            $landing = $this->resolveLandingTarget($user, $target);

            return new RedirectResponse($landing);
        }

        if (!isset($_SESSION['auth_totp_error'])) {
            $_SESSION['auth_totp_error'] = 'Código inválido ou expirado. Tente novamente.';
        }

        return new RedirectResponse(url('auth/totp'));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return new RedirectResponse(url('auth/login'));
    }

    public function heartbeat(Request $request): Response
    {
        $user = $this->auth->currentUser();

        if ($user === null) {
            return json_response([
                'status' => 'expired',
                'message' => 'Sessão expirada.',
                'redirect' => url('auth/login'),
            ], 401);
        }

        $this->auth->refreshLastSeen($user);

        return json_response([
            'status' => 'ok',
            'expires_at' => $this->auth->inactivityExpiresAt(),
            'remaining' => $this->auth->inactivityRemaining(),
        ]);
    }

    public function registerForm(Request $request): Response
    {
        if ($this->auth->currentUser() !== null) {
            return new RedirectResponse(url('/'));
        }

        if ($this->auth->hasPendingTotp()) {
            return new RedirectResponse(url('auth/totp'));
        }

        $error = $_SESSION['auth_register_error'] ?? null;
        $values = $_SESSION['auth_register_values'] ?? ['name' => '', 'email' => ''];

        unset($_SESSION['auth_register_error'], $_SESSION['auth_register_values']);

        return view('auth/register', [
            '_layout' => 'layouts/auth',
            'error' => $error,
            'values' => $values,
        ]);
    }

    public function register(Request $request): Response
    {
        $name = (string)$request->request->get('name', '');
        $email = (string)$request->request->get('email', '');
        $password = (string)$request->request->get('password', '');

        $result = $this->auth->registerPendingUser($name, $email, $password);

        if ($result->success) {
            $_SESSION['auth_notice'] = 'Solicitação registrada com sucesso. Assim que o administrador aprovar, você poderá acessar a plataforma.';
            return new RedirectResponse(url('auth/login'));
        }

        $_SESSION['auth_register_error'] = $result->message ?? 'Não foi possível registrar sua solicitação.';
        $_SESSION['auth_register_values'] = [
            'name' => trim($name),
            'email' => trim(strtolower($email)),
        ];

        return new RedirectResponse(url('auth/register'));
    }

    public function pending(Request $request): Response
    {
        $message = $_SESSION['auth_pending_message'] ?? 'Estamos aguardando a revisão do administrador.';
        unset($_SESSION['auth_pending_message']);

        return view('auth/pending', [
            '_layout' => 'layouts/auth',
            'message' => $message,
        ]);
    }

    private function resolveLandingTarget(?AuthenticatedUser $user, ?string $target): string
    {
        $default = $this->auth->defaultLandingUrl($user);

        if (!is_string($target) || $target === '') {
            return $default;
        }

        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return $default;
        }

        $normalizedPath = rtrim($path, '/');
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }

        $permissionMap = [
            '/' => ['dashboard.overview'],
            '/index.php' => ['dashboard.overview'],
            '/crm' => ['crm.overview', 'crm.dashboard.metrics', 'crm.dashboard.alerts', 'crm.dashboard.performance', 'crm.dashboard.partners', 'crm.import'],
            '/crm/clients' => ['crm.clients'],
            '/crm/clients/off' => ['crm.off'],
            '/crm/partners' => ['crm.partners'],
            '/crm/import' => ['crm.import'],
            '/campaigns/email' => ['campaigns.email'],
            '/social-accounts' => ['social_accounts.manage'],
            '/templates' => ['templates.library'],
            '/config' => ['config.manage'],
        ];

        $candidates = array_unique([$normalizedPath, $path]);
        foreach ($candidates as $candidate) {
            if (!isset($permissionMap[$candidate])) {
                continue;
            }

            foreach ($permissionMap[$candidate] as $permission) {
                if ($user !== null && $user->can($permission)) {
                    return $target;
                }
            }

            return $default;
        }

        return $default;
    }
}
