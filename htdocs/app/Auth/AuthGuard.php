<?php

declare(strict_types=1);

namespace App\Auth;

use App\Controllers\Admin\AccessRequestController;
use App\Controllers\Admin\ChatAdminController;
use App\Controllers\MarketingAutomationController;
use App\Controllers\AuthController;
use App\Controllers\AgendaController;
use App\Controllers\AutomationController;
use App\Controllers\CampaignController;
use App\Controllers\ConfigController;
use App\Controllers\CrmController;
use App\Controllers\ChatController;
use App\Controllers\DashboardController;
use App\Controllers\FinanceController;
use App\Controllers\FinanceImportController;
use App\Controllers\ProfileController;
use App\Controllers\PartnerController;
use App\Controllers\RfbBaseController;
use App\Controllers\SocialAccountController;
use App\Controllers\TemplateController;
use App\Controllers\BackupController;
use App\Controllers\MarketingController;
use App\Controllers\MarketingConsentController;
use App\Controllers\WhatsappController;
use App\Controllers\WhatsappAltController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthGuard
{
    /** @var array<class-string, array<int, string>> */
    private array $publicActions = [
        AuthController::class => ['loginForm', 'login', 'totpForm', 'totp', 'pending', 'logout', 'registerForm', 'register'],
        ChatController::class => ['externalThread', 'externalStatus', 'externalMessages', 'sendExternalMessage'],
        MarketingConsentController::class => ['show', 'update', 'downloadLogs'],
        WhatsappController::class => ['webhook', 'verifyWebhook'],
        WhatsappAltController::class => ['webhook'],
    ];

    /** @var array<class-string> */
    private array $adminControllers = [
        AccessRequestController::class,
        ChatAdminController::class,
    ];

    /** @var array<class-string, string|array<string, string>> */
    private array $controllerPermissions = [
        DashboardController::class => [
            'index' => 'dashboard.overview',
        ],
        FinanceController::class => [
            'overview' => ['finance.overview', 'dashboard.overview'],
            'calendar' => ['finance.calendar', 'finance.overview'],
            'accounts' => ['finance.accounts', 'finance.overview'],
            'manageAccounts' => 'finance.accounts',
            'createAccount' => 'finance.accounts',
            'storeAccount' => 'finance.accounts',
            'editAccount' => 'finance.accounts',
            'updateAccount' => 'finance.accounts',
            'deleteAccount' => 'finance.accounts',
            'costCenters' => 'finance.accounts',
            'storeCostCenter' => 'finance.accounts',
            'editCostCenter' => 'finance.accounts',
            'updateCostCenter' => 'finance.accounts',
            'deleteCostCenter' => 'finance.accounts',
            'transactions' => 'finance.accounts',
            'createTransaction' => 'finance.accounts',
            'storeTransaction' => 'finance.accounts',
            'editTransaction' => 'finance.accounts',
            'updateTransaction' => 'finance.accounts',
            'deleteTransaction' => 'finance.accounts',
        ],
        FinanceImportController::class => [
            'index' => 'finance.imports',
            'create' => 'finance.imports',
            'store' => 'finance.imports',
            'show' => 'finance.imports',
            'retry' => 'finance.imports',
            'cancel' => 'finance.imports',
            'importRows' => 'finance.imports',
            'skipRow' => 'finance.imports',
        ],
        AutomationController::class => [
            'start' => 'automation.control',
        ],
        CrmController::class => [
            'index' => [
                'crm.overview',
                'crm.dashboard.metrics',
                'crm.dashboard.alerts',
                'crm.dashboard.performance',
                'crm.dashboard.partners',
                'crm.import',
            ],
            'import' => 'crm.import',
            'clients' => 'crm.clients',
            'contactSearch' => 'crm.clients',
            'offClients' => 'crm.off',
            'createClient' => 'crm.clients',
            'checkClient' => 'crm.clients',
            'lookupTitular' => 'crm.clients',
            'storeClient' => 'crm.clients',
            'showClient' => 'crm.clients',
            'updateClient' => 'crm.clients',
            'moveClientOff' => 'crm.off',
            'restoreClient' => 'crm.off',
        ],
        PartnerController::class => [
            'index' => 'crm.partners',
            'store' => 'crm.partners',
        ],
        SocialAccountController::class => [
            'index' => 'social_accounts.manage',
            'store' => 'social_accounts.manage',
        ],
        CampaignController::class => [
            'email' => 'campaigns.email',
            'createEmailCampaign' => 'campaigns.email',
        ],
        TemplateController::class => [
            'index' => 'templates.library',
            'create' => 'templates.library',
            'store' => 'templates.library',
            'edit' => 'templates.library',
            'update' => 'templates.library',
            'destroy' => 'templates.library',
        ],
        MarketingController::class => [
            'lists' => 'marketing.lists',
            'createList' => 'marketing.lists',
            'storeList' => 'marketing.lists',
            'editList' => 'marketing.lists',
            'updateList' => 'marketing.lists',
            'archiveList' => 'marketing.lists',
            'segments' => 'marketing.segments',
            'createSegment' => 'marketing.segments',
            'storeSegment' => 'marketing.segments',
            'editSegment' => 'marketing.segments',
            'updateSegment' => 'marketing.segments',
            'deleteSegment' => 'marketing.segments',
            'emailAccounts' => 'marketing.email_accounts',
            'createEmailAccount' => 'marketing.email_accounts',
            'storeEmailAccount' => 'marketing.email_accounts',
            'editEmailAccount' => 'marketing.email_accounts',
            'updateEmailAccount' => 'marketing.email_accounts',
            'archiveEmailAccount' => 'marketing.email_accounts',
        ],
        RfbBaseController::class => [
            'index' => 'rfb.base',
            'updateStatus' => 'rfb.base',
        ],
        ConfigController::class => [
            'index' => 'config.manage',
            'updateEmail' => 'config.manage',
            'updateTheme' => 'config.manage',
            'updateSecurity' => 'config.manage',
            'storeSocialAccount' => 'config.manage',
            'uploadRfbBase' => 'config.manage',
            'exportClientBackup' => 'config.manage',
            'exportImportTemplate' => 'config.manage',
            'importClientSpreadsheet' => 'config.manage',
            'factoryReset' => 'config.manage',
        ],
        BackupController::class => [
            'index' => 'config.manage',
            'createFull' => 'config.manage',
            'createIncremental' => 'config.manage',
            'restore' => 'config.manage',
            'prune' => 'config.manage',
            'download' => 'config.manage',
        ],
        AgendaController::class => [
            'index' => 'crm.agenda',
            'updateConfig' => 'crm.agenda',
        ],
        WhatsappController::class => [
            'index' => 'whatsapp.access',
            'config' => 'whatsapp.access',
            'sendMessage' => 'whatsapp.access',
            'saveIntegration' => 'whatsapp.access',
            'copilotSuggestion' => 'whatsapp.access',
        ],
    ];

    public function __construct(private readonly SessionAuthService $authService)
    {
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function authorize(Request $request, array $handler): AuthGuardResult
    {
        [$controllerClass, $method] = $handler;

        if ($this->isPublic($controllerClass, $method)) {
            return AuthGuardResult::public();
        }

        if ($this->hasAutomationToken($request, $controllerClass, $method)) {
            return AuthGuardResult::public();
        }

        if ($this->authService->hasPendingTotp()) {
            return AuthGuardResult::intercepted(new RedirectResponse(url('auth/totp')));
        }

        $user = $this->authService->currentUser();

        if ($user === null) {
            if ($request->isMethod('GET')) {
                $_SESSION['auth_intended'] = $request->getRequestUri();
            }

            return AuthGuardResult::intercepted(new RedirectResponse(url('auth/login')));
        }

        if ($this->authService->passwordRequiresChange() && !$this->allowsPasswordChange($controllerClass, $method)) {
            $_SESSION['profile_password_feedback'] = $_SESSION['profile_password_feedback'] ?? [
                'type' => 'warning',
                'message' => 'Atualize sua senha expirada para continuar usando o sistema.',
            ];

            return AuthGuardResult::intercepted(new RedirectResponse(url('profile')));
        }

        if (in_array($controllerClass, $this->adminControllers, true) && !$user->isAdmin()) {
            return AuthGuardResult::intercepted(new Response('Acesso permitido somente ao administrador.', 403));
        }

        $requiredPermission = $this->permissionFor($controllerClass, $method);

        if (is_array($requiredPermission)) {
            $allowed = false;
            foreach ($requiredPermission as $permission) {
                if ($user->can($permission)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                return AuthGuardResult::intercepted(new Response('Permissão insuficiente para acessar este módulo.', 403));
            }
        } elseif (is_string($requiredPermission)) {
            if (!$user->can($requiredPermission)) {
                return AuthGuardResult::intercepted(new Response('Permissão insuficiente para acessar este módulo.', 403));
            }
        }

        $this->authService->refreshLastSeen($user);

        return AuthGuardResult::authenticated($user);
    }

    private function isPublic(string $controllerClass, string $method): bool
    {
        if (!isset($this->publicActions[$controllerClass])) {
            return false;
        }

        return in_array($method, $this->publicActions[$controllerClass], true);
    }

    /**
     * @return string|array<int, string>|null
     */
    private function permissionFor(string $controllerClass, string $method)
    {
        if (!isset($this->controllerPermissions[$controllerClass])) {
            return null;
        }

        $map = $this->controllerPermissions[$controllerClass];

        if (is_string($map)) {
            return $map;
        }

        if (isset($map[$method])) {
            return $map[$method];
        }

        return $map['*'] ?? null;
    }

    private function allowsPasswordChange(string $controllerClass, string $method): bool
    {
        if ($controllerClass === ProfileController::class) {
            return in_array($method, ['show', 'updatePassword'], true);
        }

        if ($controllerClass === AuthController::class) {
            return in_array($method, ['logout', 'heartbeat'], true);
        }

        return false;
    }

    private function hasAutomationToken(Request $request, string $controllerClass, string $method): bool
    {
        if ($controllerClass !== MarketingAutomationController::class) {
            return false;
        }

        $allowedMethods = ['emailOptions', 'scheduleEmail'];
        if (!in_array($method, $allowedMethods, true)) {
            return false;
        }

        $expected = trim((string)config('app.automation_token', ''));
        if ($expected === '') {
            return false;
        }

        $provided = $request->headers->get('X-Automation-Token');
        if (is_string($provided) && $provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        $queryToken = $request->query->get('token');
        return is_string($queryToken) && $queryToken !== '' && hash_equals($expected, $queryToken);
    }
}
