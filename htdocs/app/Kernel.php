<?php

declare(strict_types=1);

namespace App;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use App\Auth\AuthGuard;
use App\Auth\SessionAuthService;
use App\Controllers\DashboardController;
use App\Controllers\AutomationController;
use App\Controllers\AgendaController;
use App\Controllers\ChatController;
use App\Controllers\CrmController;
use App\Controllers\FinanceController;
use App\Controllers\FinanceImportController;
use App\Controllers\RfbBaseController;
use App\Controllers\SocialAccountController;
use App\Controllers\CampaignController;
use App\Controllers\EmailController;
use App\Controllers\TemplateController;
use App\Controllers\BackupController;
use App\Controllers\PartnerController;
use App\Controllers\ConfigController;
use App\Controllers\AuthController;
use App\Controllers\ProfileController;
use App\Controllers\Admin\AccessRequestController;
use App\Controllers\Admin\ChatAdminController;
use App\Controllers\MarketingController;
use App\Controllers\MarketingAutomationController;
use App\Controllers\MarketingConsentController;
use App\Controllers\WhatsappController;
use App\Controllers\WhatsappAltController;
use App\Security\CsrfTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function FastRoute\cachedDispatcher;
use function get_debug_type;
use function storage_path;

final class Kernel
{
    private Dispatcher $dispatcher;
    private AuthGuard $authGuard;
    /** @var array<string, string[]> */
    private array $csrfExcept = [
        'POST' => [
            '/chat/external-thread',
            '/chat/external-thread/*/claim',
            '/chat/external-thread/*/messages',
            '/whatsapp/webhook',
            '/whatsapp/alt/webhook/incoming',
        ],
    ];
    /** @var array<string, array<string, true>> */
    private array $registeredRoutes = [];

    public function __construct()
    {
        $cacheDir = storage_path('cache');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'fastroute.cache.php';

        // Cache compiled FastRoute data to speed up route dispatch.
        $this->dispatcher = cachedDispatcher(function (RouteCollector $routes): void {
            $routes->addRoute('GET', '/', [DashboardController::class, 'index']);
            $routes->addRoute('POST', '/automation/start', [AutomationController::class, 'start']);

            $routes->addRoute('GET', '/finance', [FinanceController::class, 'overview']);
            $routes->addRoute('GET', '/finance/calendar', [FinanceController::class, 'calendar']);
            $routes->addRoute('GET', '/finance/accounts', [FinanceController::class, 'accounts']);
            $routes->addRoute('GET', '/finance/accounts/manage', [FinanceController::class, 'manageAccounts']);
            $routes->addRoute('GET', '/finance/accounts/create', [FinanceController::class, 'createAccount']);
            $routes->addRoute('POST', '/finance/accounts', [FinanceController::class, 'storeAccount']);
            $routes->addRoute('GET', '/finance/accounts/{id:\d+}/edit', [FinanceController::class, 'editAccount']);
            $routes->addRoute('POST', '/finance/accounts/{id:\d+}/update', [FinanceController::class, 'updateAccount']);
            $routes->addRoute('POST', '/finance/accounts/{id:\d+}/delete', [FinanceController::class, 'deleteAccount']);
            $routes->addRoute('GET', '/finance/cost-centers', [FinanceController::class, 'costCenters']);
            $routes->addRoute('POST', '/finance/cost-centers', [FinanceController::class, 'storeCostCenter']);
            $routes->addRoute('GET', '/finance/cost-centers/{id:\d+}/edit', [FinanceController::class, 'editCostCenter']);
            $routes->addRoute('POST', '/finance/cost-centers/{id:\d+}/update', [FinanceController::class, 'updateCostCenter']);
            $routes->addRoute('POST', '/finance/cost-centers/{id:\d+}/delete', [FinanceController::class, 'deleteCostCenter']);
            $routes->addRoute('GET', '/finance/transactions', [FinanceController::class, 'transactions']);
            $routes->addRoute('GET', '/finance/transactions/create', [FinanceController::class, 'createTransaction']);
            $routes->addRoute('POST', '/finance/transactions', [FinanceController::class, 'storeTransaction']);
            $routes->addRoute('GET', '/finance/transactions/{id:\d+}/edit', [FinanceController::class, 'editTransaction']);
            $routes->addRoute('POST', '/finance/transactions/{id:\d+}/update', [FinanceController::class, 'updateTransaction']);
            $routes->addRoute('POST', '/finance/transactions/{id:\d+}/delete', [FinanceController::class, 'deleteTransaction']);
            $routes->addRoute('GET', '/finance/imports', [FinanceImportController::class, 'index']);
            $routes->addRoute('GET', '/finance/imports/create', [FinanceImportController::class, 'create']);
            $routes->addRoute('POST', '/finance/imports', [FinanceImportController::class, 'store']);
            $routes->addRoute('GET', '/finance/imports/{id:\d+}', [FinanceImportController::class, 'show']);
            $routes->addRoute('POST', '/finance/imports/{id:\d+}/retry', [FinanceImportController::class, 'retry']);
            $routes->addRoute('POST', '/finance/imports/{id:\d+}/cancel', [FinanceImportController::class, 'cancel']);
            $routes->addRoute('POST', '/finance/imports/{id:\d+}/rows/import', [FinanceImportController::class, 'importRows']);
            $routes->addRoute('POST', '/finance/imports/{batch:\d+}/rows/{row:\d+}/skip', [FinanceImportController::class, 'skipRow']);

            $routes->addRoute('GET', '/social-accounts', [SocialAccountController::class, 'index']);
            $routes->addRoute('POST', '/social-accounts', [SocialAccountController::class, 'store']);

            $routes->addRoute('GET', '/crm', [CrmController::class, 'index']);
            $routes->addRoute('POST', '/crm/import', [CrmController::class, 'import']);
            $routes->addRoute('GET', '/crm/clients', [CrmController::class, 'clients']);
            $routes->addRoute('GET', '/crm/clients/contact-search', [CrmController::class, 'contactSearch']);
            $routes->addRoute('GET', '/crm/clients/create', [CrmController::class, 'createClient']);
            $routes->addRoute('POST', '/crm/clients/check', [CrmController::class, 'checkClient']);
            $routes->addRoute('POST', '/crm/clients/lookup-titular', [CrmController::class, 'lookupTitular']);
            $routes->addRoute('POST', '/crm/clients', [CrmController::class, 'storeClient']);
            $routes->addRoute('GET', '/crm/clients/off', [CrmController::class, 'offClients']);
            $routes->addRoute('GET', '/crm/clients/{id:\d+}', [CrmController::class, 'showClient']);
            $routes->addRoute('POST', '/crm/clients/{id:\d+}/update', [CrmController::class, 'updateClient']);
            $routes->addRoute('POST', '/crm/clients/{id:\d+}/marks', [CrmController::class, 'markClientAction']);
            $routes->addRoute('POST', '/crm/clients/{id:\d+}/off', [CrmController::class, 'moveClientOff']);
            $routes->addRoute('POST', '/crm/clients/{id:\d+}/restore', [CrmController::class, 'restoreClient']);
            $routes->addRoute('POST', '/crm/clients/{id:\d+}/protocols', [CrmController::class, 'storeProtocol']);
            $routes->addRoute('POST', '/crm/clients/{id:\d+}/protocols/{protocolId:\d+}/update', [CrmController::class, 'updateProtocol']);
            $routes->addRoute('POST', '/crm/clients/{id:\d+}/protocols/{protocolId:\d+}/delete', [CrmController::class, 'deleteProtocol']);
            $routes->addRoute('GET', '/crm/partners', [PartnerController::class, 'index']);
            $routes->addRoute('GET', '/crm/partners/autocomplete', [PartnerController::class, 'autocomplete']);
            $routes->addRoute('POST', '/crm/partners', [PartnerController::class, 'store']);
            $routes->addRoute('POST', '/crm/partners/link', [PartnerController::class, 'linkClient']);
            $routes->addRoute('POST', '/crm/partners/report', [PartnerController::class, 'saveReport']);

            $routes->addRoute('GET', '/rfb-base', [RfbBaseController::class, 'index']);
            $routes->addRoute('GET', '/rfb-base/options/cities', [RfbBaseController::class, 'cityOptions']);
            $routes->addRoute('GET', '/rfb-base/options/cnaes', [RfbBaseController::class, 'cnaeOptions']);
            $routes->addRoute('POST', '/rfb-base/{id:\d+}/status', [RfbBaseController::class, 'updateStatus']);
            $routes->addRoute('POST', '/rfb-base/{id:\d+}/contact', [RfbBaseController::class, 'updateContact']);

            $routes->addRoute('GET', '/config', [ConfigController::class, 'index']);
            $routes->addRoute('GET', '/config/manual', [ConfigController::class, 'downloadManual']);
            $routes->addRoute('GET', '/config/manual/whatsapp', [ConfigController::class, 'whatsappManual']);
            $routes->addRoute('POST', '/config/email', [ConfigController::class, 'updateEmail']);
            $routes->addRoute('POST', '/config/theme', [ConfigController::class, 'updateTheme']);
            $routes->addRoute('POST', '/config/security', [ConfigController::class, 'updateSecurity']);
            $routes->addRoute('POST', '/config/rfb-base-upload', [ConfigController::class, 'uploadRfbBase']);
            $routes->addRoute('POST', '/config/social-accounts', [ConfigController::class, 'storeSocialAccount']);
            $routes->addRoute('POST', '/config/export-backup', [ConfigController::class, 'exportClientBackup']);
            $routes->addRoute('POST', '/config/export-import-template', [ConfigController::class, 'exportImportTemplate']);
            $routes->addRoute('POST', '/config/import-spreadsheet', [ConfigController::class, 'importClientSpreadsheet']);
            $routes->addRoute('POST', '/config/import-settings', [ConfigController::class, 'updateImportSettings']);
            $routes->addRoute('POST', '/config/whatsapp-templates', [ConfigController::class, 'updateWhatsappTemplates']);
            $routes->addRoute('POST', '/config/renewal-window', [ConfigController::class, 'updateRenewalWindow']);
            $routes->addRoute('POST', '/config/rfb-whatsapp-templates', [ConfigController::class, 'updateRfbWhatsappTemplates']);
            $routes->addRoute('POST', '/config/factory-reset', [ConfigController::class, 'factoryReset']);
            $routes->addRoute('POST', '/config/releases/import', [ConfigController::class, 'importRelease']);
            $routes->addRoute('POST', '/config/releases/{id:\d+}/apply', [ConfigController::class, 'applyRelease']);
            $routes->addRoute('GET', '/config/releases/{id:\d+}/download', [ConfigController::class, 'downloadRelease']);
            $routes->addRoute('POST', '/config/releases/generate', [ConfigController::class, 'generateRelease']);
            $routes->addRoute('POST', '/config/route-cache/refresh', [ConfigController::class, 'refreshRouteCache']);

            $routes->addRoute('GET', '/backup-manager', [BackupController::class, 'index']);
            $routes->addRoute('POST', '/backup-manager/full', [BackupController::class, 'createFull']);
            $routes->addRoute('POST', '/backup-manager/incremental', [BackupController::class, 'createIncremental']);
            $routes->addRoute('POST', '/backup-manager/restore', [BackupController::class, 'restore']);
            $routes->addRoute('POST', '/backup-manager/prune', [BackupController::class, 'prune']);
            $routes->addRoute('GET', '/backup-manager/{id:[A-Za-z0-9_\-]+}/download', [BackupController::class, 'download']);

            $routes->addRoute('GET', '/agenda', [AgendaController::class, 'index']);
            $routes->addRoute('GET', '/public/agenda', [AgendaController::class, 'publicView']);
            $routes->addRoute('GET', '/agenda/availability', [AgendaController::class, 'availability']);
            $routes->addRoute('POST', '/agenda/config', [AgendaController::class, 'updateConfig']);
            $routes->addRoute('POST', '/agenda/appointments', [AgendaController::class, 'storeAppointment']);
            $routes->addRoute('POST', '/agenda/appointments/{id:\d+}/update', [AgendaController::class, 'updateAppointment']);
            $routes->addRoute('POST', '/agenda/appointments/{id:\d+}/delete', [AgendaController::class, 'deleteAppointment']);

            $routes->addRoute('GET', '/chat', [ChatController::class, 'index']);
            $routes->addRoute('GET', '/chat/threads', [ChatController::class, 'threads']);
            $routes->addRoute('POST', '/chat/threads', [ChatController::class, 'startThread']);
            $routes->addRoute('POST', '/chat/groups', [ChatController::class, 'createGroup']);
            $routes->addRoute('GET', '/chat/threads/{id:\d+}/messages', [ChatController::class, 'messages']);
            $routes->addRoute('POST', '/chat/threads/{id:\d+}/messages', [ChatController::class, 'sendMessage']);
            $routes->addRoute('POST', '/chat/threads/{id:\d+}/close', [ChatController::class, 'closeThread']);
            $routes->addRoute('POST', '/chat/threads/{id:\d+}/read', [ChatController::class, 'markRead']);
            $routes->addRoute('POST', '/chat/external-thread', [ChatController::class, 'externalThread']);
            $routes->addRoute('POST', '/chat/external-thread/{id:\d+}/claim', [ChatController::class, 'claimExternal']);
            $routes->addRoute('GET', '/chat/external-thread/{token:[a-f0-9]{32}}/status', [ChatController::class, 'externalStatus']);
            $routes->addRoute('GET', '/chat/external-thread/{token:[a-f0-9]{32}}/messages', [ChatController::class, 'externalMessages']);
            $routes->addRoute('POST', '/chat/external-thread/{token:[a-f0-9]{32}}/messages', [ChatController::class, 'sendExternalMessage']);

            $routes->addRoute('GET', '/email/inbox', [EmailController::class, 'inbox']);
            $routes->addRoute('GET', '/email/inbox/threads', [EmailController::class, 'threads']);
            $routes->addRoute('GET', '/email/inbox/search', [EmailController::class, 'search']);
            $routes->addRoute('GET', '/email/inbox/threads/{id:\d+}/messages', [EmailController::class, 'threadMessages']);
            $routes->addRoute('POST', '/email/inbox/threads/{id:\d+}/read', [EmailController::class, 'markThreadRead']);
            $routes->addRoute('POST', '/email/inbox/threads/{id:\d+}/actions', [EmailController::class, 'threadAction']);
            $routes->addRoute('POST', '/email/inbox/threads/{id:\d+}/star', [EmailController::class, 'starThread']);
            $routes->addRoute('POST', '/email/inbox/threads/{id:\d+}/archive', [EmailController::class, 'archiveThread']);
            $routes->addRoute('POST', '/email/inbox/threads/{id:\d+}/move', [EmailController::class, 'moveThread']);
            $routes->addRoute('POST', '/email/inbox/threads/bulk-actions', [EmailController::class, 'bulkThreadAction']);
            $routes->addRoute('POST', '/email/inbox/trash/empty', [EmailController::class, 'emptyTrash']);
            $routes->addRoute('POST', '/email/inbox/accounts/sync', [EmailController::class, 'syncAccount']);
            $routes->addRoute('POST', '/email/inbox/accounts/{id:\d+}/sync', [EmailController::class, 'syncAccount']);
            $routes->addRoute('GET', '/email/inbox/messages/{id:\d+}', [EmailController::class, 'message']);
            $routes->addRoute('GET', '/email/inbox/messages/{id:\d+}/view', [EmailController::class, 'messageView']);
            $routes->addRoute('GET', '/email/inbox/attachments/{id:\d+}/download', [EmailController::class, 'downloadAttachment']);
            $routes->addRoute('POST', '/email/inbox/compose', [EmailController::class, 'compose']);
            $routes->addRoute('POST', '/email/inbox/compose/draft', [EmailController::class, 'saveDraft']);
            $routes->addRoute('GET', '/email/inbox/compose/drafts', [EmailController::class, 'drafts']);
            $routes->addRoute('GET', '/email/inbox/compose/window', [EmailController::class, 'composeWindow']);
            $routes->addRoute('GET', '/email/inbox/compose/audience-recipients', [EmailController::class, 'composeAudienceRecipients']);

            $routes->addRoute('GET', '/campaigns/email', [CampaignController::class, 'email']);
            $routes->addRoute('POST', '/campaigns/email', [CampaignController::class, 'createEmailCampaign']);
            $routes->addRoute('POST', '/campaigns/email/automation', [CampaignController::class, 'saveAutomation']);

            $routes->addRoute('GET', '/templates', [TemplateController::class, 'index']);
            $routes->addRoute('GET', '/templates/create', [TemplateController::class, 'create']);
            $routes->addRoute('POST', '/templates', [TemplateController::class, 'store']);
            $routes->addRoute('GET', '/templates/{id:\\d+}/edit', [TemplateController::class, 'edit']);
            $routes->addRoute('POST', '/templates/{id:\\d+}/update', [TemplateController::class, 'update']);
            $routes->addRoute('POST', '/templates/{id:\\d+}/delete', [TemplateController::class, 'destroy']);

            $routes->addRoute('GET', '/preferences/{token:[a-f0-9]{16,64}}', [MarketingConsentController::class, 'show']);
            $routes->addRoute('POST', '/preferences/{token:[a-f0-9]{16,64}}', [MarketingConsentController::class, 'update']);
            $routes->addRoute('GET', '/preferences/{token:[a-f0-9]{16,64}}/logs', [MarketingConsentController::class, 'downloadLogs']);

            $routes->addRoute('GET', '/marketing/lists', [MarketingController::class, 'lists']);
            $routes->addRoute('GET', '/marketing/lists/create', [MarketingController::class, 'createList']);
            $routes->addRoute('POST', '/marketing/lists', [MarketingController::class, 'storeList']);
            $routes->addRoute('GET', '/marketing/lists/{id:\\d+}/edit', [MarketingController::class, 'editList']);
            $routes->addRoute('POST', '/marketing/lists/{id:\\d+}/update', [MarketingController::class, 'updateList']);
            $routes->addRoute('POST', '/marketing/lists/{id:\\d+}/archive', [MarketingController::class, 'archiveList']);
               $routes->addRoute('POST', '/marketing/lists/{id:\d+}/refresh', [MarketingController::class, 'refreshList']);
            $routes->addRoute('GET', '/marketing/lists/{id:\\d+}/import', [MarketingController::class, 'importList']);
            $routes->addRoute('POST', '/marketing/lists/{id:\\d+}/import', [MarketingController::class, 'processImport']);
            $routes->addRoute('POST', '/marketing/lists/{id:\d+}/pdf-test', [MarketingController::class, 'pdfTestSend']);
                $routes->addRoute('POST', '/marketing/lists/{id:\d+}/contacts/add', [MarketingController::class, 'addListContacts']);
                $routes->addRoute('POST', '/marketing/lists/{id:\d+}/contacts/{contact_id:\d+}/unsubscribe', [MarketingController::class, 'unsubscribeListContact']);
                $routes->addRoute('POST', '/marketing/lists/{id:\d+}/contacts/{contact_id:\d+}/resubscribe', [MarketingController::class, 'resubscribeListContact']);
                $routes->addRoute('GET', '/marketing/lists/{id:\d+}/contacts/search', [MarketingController::class, 'searchListContacts']);
               $routes->addRoute('POST', '/marketing/lists/suppress-upload', [MarketingController::class, 'suppressUpload']);
            $routes->addRoute('POST', '/marketing/scheduled/{id:\d+}/update', [MarketingController::class, 'updateScheduledEmail']);
            $routes->addRoute('POST', '/marketing/scheduled/{id:\d+}/cancel', [MarketingController::class, 'cancelScheduledEmail']);
            $routes->addRoute('GET', '/marketing/automations', [MarketingController::class, 'automations']);
            $routes->addRoute('GET', '/marketing/automations/birthday/status', [MarketingAutomationController::class, 'birthdayStatus']);
            $routes->addRoute('POST', '/marketing/automations/birthday/auto', [MarketingAutomationController::class, 'toggleAuto']);
            $routes->addRoute('POST', '/marketing/automations/birthday/schedule', [MarketingAutomationController::class, 'schedule']);
            $routes->addRoute('POST', '/marketing/automations/birthday/run', [MarketingAutomationController::class, 'run']);
            $routes->addRoute('GET', '/marketing/automations/renewal/status', [MarketingAutomationController::class, 'renewalStatus']);
            $routes->addRoute('GET', '/marketing/automations/email/options', [MarketingAutomationController::class, 'emailOptions']);
            $routes->addRoute('POST', '/marketing/automations/email/schedule', [MarketingAutomationController::class, 'scheduleEmail']);
            $routes->addRoute('GET', '/marketing/automations/blocking', [MarketingAutomationController::class, 'blockingStatus']);
            $routes->addRoute('POST', '/marketing/automations/blocking', [MarketingAutomationController::class, 'saveBlocking']);
            $routes->addRoute('GET', '/marketing/automations/renewal/forecast', [MarketingAutomationController::class, 'forecastRenewal']);
            $routes->addRoute('POST', '/marketing/automations/renewal/auto', [MarketingAutomationController::class, 'toggleRenewalAuto']);
            $routes->addRoute('POST', '/marketing/automations/renewal/schedule', [MarketingAutomationController::class, 'scheduleRenewal']);
            $routes->addRoute('POST', '/marketing/automations/renewal/run', [MarketingAutomationController::class, 'runRenewal']);
            $routes->addRoute('GET', '/marketing/segments', [MarketingController::class, 'segments']);
            $routes->addRoute('GET', '/marketing/segments/create', [MarketingController::class, 'createSegment']);
            $routes->addRoute('POST', '/marketing/segments', [MarketingController::class, 'storeSegment']);
            $routes->addRoute('GET', '/marketing/segments/{id:\\d+}/edit', [MarketingController::class, 'editSegment']);
            $routes->addRoute('POST', '/marketing/segments/{id:\\d+}/update', [MarketingController::class, 'updateSegment']);
            $routes->addRoute('POST', '/marketing/segments/{id:\\d+}/delete', [MarketingController::class, 'deleteSegment']);
            $routes->addRoute('GET', '/marketing/email-accounts', [MarketingController::class, 'emailAccounts']);
            $routes->addRoute('GET', '/marketing/email-accounts/create', [MarketingController::class, 'createEmailAccount']);
            $routes->addRoute('POST', '/marketing/email-accounts', [MarketingController::class, 'storeEmailAccount']);
            $routes->addRoute('GET', '/marketing/email-accounts/{id:\\d+}/edit', [MarketingController::class, 'editEmailAccount']);
            $routes->addRoute('POST', '/marketing/email-accounts/{id:\\d+}/update', [MarketingController::class, 'updateEmailAccount']);
            $routes->addRoute('POST', '/marketing/email-accounts/{id:\\d+}/archive', [MarketingController::class, 'archiveEmailAccount']);

            $routes->addRoute('GET', '/whatsapp', [WhatsappController::class, 'index']);
            $routes->addRoute('GET', '/whatsapp/', [WhatsappController::class, 'index']);
            $routes->addRoute('GET', '/whatsapp/media/{message:\d+}', [WhatsappController::class, 'media']);
            $routes->addRoute('GET', '/whatsapp/thread-poll', [WhatsappController::class, 'pollThread']);
            $routes->addRoute('GET', '/whatsapp/panel-refresh', [WhatsappController::class, 'panelRefresh']);
            $routes->addRoute('POST', '/whatsapp/send-message', [WhatsappController::class, 'sendMessage']);
            $routes->addRoute('POST', '/whatsapp/internal-note', [WhatsappController::class, 'storeInternalNote']);
            $routes->addRoute('POST', '/whatsapp/integration', [WhatsappController::class, 'saveIntegration']);
            $routes->addRoute('POST', '/whatsapp/contact-tags', [WhatsappController::class, 'updateContact']);
            $routes->addRoute('POST', '/whatsapp/access-control', [WhatsappController::class, 'updateAccessControl']);
                $routes->addRoute('POST', '/whatsapp/blocked-numbers', [WhatsappController::class, 'updateBlockedNumbers']);
                $routes->addRoute('POST', '/whatsapp/block-contact', [WhatsappController::class, 'blockContact']);
            $routes->addRoute('POST', '/whatsapp/assign-thread', [WhatsappController::class, 'assignThread']);
               $routes->addRoute('POST', '/whatsapp/update-contact-phone', [WhatsappController::class, 'updateContactPhone']);
                $routes->addRoute('POST', '/whatsapp/register-contact', [WhatsappController::class, 'registerContact']);
                $routes->addRoute('GET', '/whatsapp/client-summary', [WhatsappController::class, 'clientSummary']);
            $routes->addRoute('POST', '/whatsapp/thread-status', [WhatsappController::class, 'updateThreadStatus']);
            $routes->addRoute('POST', '/whatsapp/thread-queue', [WhatsappController::class, 'updateQueue']);
            $routes->addRoute('POST', '/whatsapp/broadcast', [WhatsappController::class, 'broadcast']);
            $routes->addRoute('POST', '/whatsapp/manual-thread', [WhatsappController::class, 'startManualThread']);
            $routes->addRoute('POST', '/whatsapp/lines', [WhatsappController::class, 'storeLine']);
            $routes->addRoute('POST', '/whatsapp/lines/{lineId:\d+}', [WhatsappController::class, 'updateLine']);
            $routes->addRoute('POST', '/whatsapp/lines/{lineId:\d+}/delete', [WhatsappController::class, 'deleteLine']);
            $routes->addRoute('POST', '/whatsapp/copilot-suggestion', [WhatsappController::class, 'copilotSuggestion']);
            $routes->addRoute('POST', '/whatsapp/copilot-profiles', [WhatsappController::class, 'storeCopilotProfile']);
            $routes->addRoute('POST', '/whatsapp/copilot-profiles/{profileId:\d+}', [WhatsappController::class, 'updateCopilotProfile']);
            $routes->addRoute('POST', '/whatsapp/copilot-profiles/{profileId:\d+}/delete', [WhatsappController::class, 'deleteCopilotProfile']);
            $routes->addRoute('POST', '/whatsapp/copilot-manuals', [WhatsappController::class, 'uploadManual']);
            $routes->addRoute('POST', '/whatsapp/copilot-manuals/{manualId:\d+}/delete', [WhatsappController::class, 'deleteManual']);
            $routes->addRoute('GET', '/whatsapp/copilot-manuals/{manualId:\d+}/download', [WhatsappController::class, 'downloadManual']);
            $routes->addRoute('POST', '/whatsapp/pre-triage', [WhatsappController::class, 'runPreTriage']);
            $routes->addRoute('POST', '/whatsapp/sandbox/inject', [WhatsappController::class, 'injectSandboxMessage']);
            $routes->addRoute('POST', '/whatsapp/user-permissions', [WhatsappController::class, 'updateUserPermissions']);
            $routes->addRoute('GET', '/whatsapp/webhook', [WhatsappController::class, 'verifyWebhook']);
            $routes->addRoute('POST', '/whatsapp/webhook', [WhatsappController::class, 'webhook']);
            $routes->addRoute('GET', '/whatsapp/config', [WhatsappController::class, 'config']);
            $routes->addRoute('GET', '/whatsapp/config/guide-pdf', [WhatsappController::class, 'guidePdf']);
            $routes->addRoute('POST', '/whatsapp/backup/export', [WhatsappController::class, 'exportBackup']);
            $routes->addRoute('POST', '/whatsapp/backup/import', [WhatsappController::class, 'importBackup']);
            $routes->addRoute('POST', '/whatsapp/alt/gateway-backup/import', [WhatsappController::class, 'importGatewayBackup']);
            $routes->addRoute('GET', '/whatsapp/alt/gateway-backup/summary', [WhatsappController::class, 'gatewayBackupSummary']);
            $routes->addRoute('GET', '/whatsapp/alt/gateway-status', [WhatsappAltController::class, 'gatewayStatus']);
            $routes->addRoute('GET', '/whatsapp/alt/qr', [WhatsappAltController::class, 'gatewayQr']);
            $routes->addRoute('POST', '/whatsapp/alt/gateway-start', [WhatsappAltController::class, 'gatewayStart']);
            $routes->addRoute('POST', '/whatsapp/alt/gateway-stop', [WhatsappAltController::class, 'gatewayStop']);
            $routes->addRoute('POST', '/whatsapp/alt/gateway-reset', [WhatsappAltController::class, 'gatewayReset']);
            $routes->addRoute('POST', '/whatsapp/alt/history-sync', [WhatsappAltController::class, 'gatewayHistorySync']);
            $routes->addRoute('POST', '/whatsapp/alt/send', [WhatsappAltController::class, 'sendViaGateway']);
            $routes->addRoute('POST', '/whatsapp/alt/webhook/incoming', [WhatsappAltController::class, 'webhook']);

            $routes->addRoute('GET', '/auth/login', [AuthController::class, 'loginForm']);
            $routes->addRoute('POST', '/auth/login', [AuthController::class, 'login']);
            $routes->addRoute('GET', '/auth/register', [AuthController::class, 'registerForm']);
            $routes->addRoute('POST', '/auth/register', [AuthController::class, 'register']);
            $routes->addRoute('GET', '/auth/totp', [AuthController::class, 'totpForm']);
            $routes->addRoute('POST', '/auth/totp', [AuthController::class, 'totp']);
            $routes->addRoute('POST', '/auth/logout', [AuthController::class, 'logout']);
            $routes->addRoute('GET', '/auth/pending', [AuthController::class, 'pending']);
            $routes->addRoute('POST', '/auth/heartbeat', [AuthController::class, 'heartbeat']);

            $routes->addRoute('GET', '/profile', [ProfileController::class, 'show']);
            $routes->addRoute('POST', '/profile/details', [ProfileController::class, 'updateDetails']);
            $routes->addRoute('POST', '/profile/password', [ProfileController::class, 'updatePassword']);

            $routes->addRoute('GET', '/admin/access-requests', [AccessRequestController::class, 'index']);
            $routes->addRoute('POST', '/admin/access-requests/{id:\\d+}/approve', [AccessRequestController::class, 'approve']);
            $routes->addRoute('POST', '/admin/access-requests/{id:\\d+}/deny', [AccessRequestController::class, 'deny']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/approve', [AccessRequestController::class, 'approveLogin']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/deny', [AccessRequestController::class, 'denyLogin']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/permissions', [AccessRequestController::class, 'updatePermissions']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/access-window', [AccessRequestController::class, 'updateAccessWindow']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/identity', [AccessRequestController::class, 'updateIdentity']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/identity/lookup', [AccessRequestController::class, 'lookupIdentity']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/identity/link', [AccessRequestController::class, 'linkIdentityFromClient']);
            $routes->addRoute('POST', '/admin/admins/{id:\d+}/device-policy', [AccessRequestController::class, 'updateAdminDevicePolicy']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/client-access/scope', [AccessRequestController::class, 'updateClientAccessScope']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/client-access/allow-online', [AccessRequestController::class, 'updateAllowOnlineClients']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/chat-permissions', [AccessRequestController::class, 'updateChatPermissions']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/chat-identifier', [AccessRequestController::class, 'updateChatIdentifier']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/avp-profile', [AccessRequestController::class, 'updateAvpProfile']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/client-access/add', [AccessRequestController::class, 'grantClientAccess']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/client-access/sync', [AccessRequestController::class, 'syncClientAccessFromHistory']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/client-access/{clientId:\d+}/remove', [AccessRequestController::class, 'revokeClientAccess']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/force-off', [AccessRequestController::class, 'forceLogout']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/reset-password', [AccessRequestController::class, 'resetPassword']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/deactivate', [AccessRequestController::class, 'deactivate']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/activate', [AccessRequestController::class, 'activate']);
            $routes->addRoute('POST', '/admin/users/{id:\d+}/delete', [AccessRequestController::class, 'delete']);
            $routes->addRoute('POST', '/admin/chat/purge', [ChatAdminController::class, 'purge']);
            $routes->addRoute('POST', '/admin/chat/policy', [ChatAdminController::class, 'updatePolicy']);

            // --- Bounce sweep endpoints ---
            $this->registerRoute($routes, 'POST', '/marketing/lists/sweep/start', [\App\Controllers\MarketingListSweepController::class, 'start']);
            $this->registerRoute($routes, 'POST', '/marketing/lists/sweep/stop', [\App\Controllers\MarketingListSweepController::class, 'stop']);
            $this->registerRoute($routes, 'GET', '/marketing/lists/sweep/status', [\App\Controllers\MarketingListSweepController::class, 'status']);
            $this->registerRoute($routes, 'POST', '/marketing/lists/sweep/process', [\App\Controllers\MarketingListSweepController::class, 'processBatch']);
            $this->registerRoute($routes, 'GET', '/marketing/lists/suppressions', [\App\Controllers\MarketingListSweepController::class, 'suppressions']);
            $this->registerRoute($routes, 'POST', '/marketing/lists/suppressions/import', [\App\Controllers\MarketingListSweepController::class, 'importSuppressions']);
            $this->registerRoute($routes, 'GET', '/marketing/lists/suppressions/export', [\App\Controllers\MarketingListSweepController::class, 'exportSuppressions']);
            $this->registerRoute($routes, 'POST', '/marketing/lists/suppressions/unsuppress', [\App\Controllers\MarketingListSweepController::class, 'unsuppress']);
            $this->registerRoute($routes, 'GET', '/marketing/lists/sweep/history', [\App\Controllers\MarketingListSweepController::class, 'history']);
        }, [
            'cacheFile' => $cacheFile,
            'cacheDisabled' => (bool)config('app.debug', false),
        ]);

        $this->authGuard = new AuthGuard(new SessionAuthService());
    }

    public function handle(Request $request): Response
    {
        $routeInfo = $this->dispatcher->dispatch($request->getMethod(), $request->getPathInfo());

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => abort(404, 'Página não encontrada'),
            Dispatcher::METHOD_NOT_ALLOWED => abort(405, 'Método não permitido'),
            Dispatcher::FOUND => $this->dispatchToController($routeInfo, $request),
            default => abort(500, 'Erro inesperado')
        };
    }

    private function dispatchToController(array $routeInfo, Request $request): Response
    {
        [$status, $handler, $vars] = $routeInfo;
        if (!is_array($handler) || count($handler) !== 2) {
            throw new \RuntimeException('Rota configurada com handler inválido.');
        }

        $guardResult = $this->authGuard->authorize($request, $handler);
        if ($guardResult->response instanceof Response) {
            return $guardResult->response;
        }

        if ($this->requiresCsrfValidation($request)) {
            if (!CsrfTokenManager::validate($request)) {
                return new Response('Solicitação expirada. Atualize a página e tente novamente.', 419);
            }
        }

        if ($guardResult->user !== null) {
            $request->attributes->set('user', $guardResult->user);
        }

        [$class, $method] = $handler;

        $controller = new $class();
        $response = $controller->$method($request, $vars);

        if (!$response instanceof Response) {
            throw new \RuntimeException(sprintf(
                'O controlador %s::%s deve retornar uma instância de %s.',
                $class,
                $method,
                Response::class
            ));
        }

        return $response;
    }

    private function requiresCsrfValidation(Request $request): bool
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = $request->getPathInfo();
        $exceptions = $this->csrfExcept[$method] ?? [];

        foreach ($exceptions as $pattern) {
            if (strpos($pattern, '*') !== false) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $path) === 1) {
                    return false;
                }
                continue;
            }

            if ($pattern === $path) {
                return false;
            }
        }

        return true;
    }

    private function registerRoute(RouteCollector $routes, string $method, string $path, array $handler): void
    {
        $methodKey = strtoupper($method);
        if (!isset($this->registeredRoutes[$methodKey])) {
            $this->registeredRoutes[$methodKey] = [];
        }

        if (isset($this->registeredRoutes[$methodKey][$path])) {
            $controller = is_array($handler) && isset($handler[0])
                ? (is_string($handler[0]) ? $handler[0] : get_debug_type($handler[0]))
                : 'handler';
            $action = is_array($handler) && isset($handler[1]) ? (string)$handler[1] : 'method';

            error_log(sprintf('[Kernel] Duplicate route skipped: %s %s (%s::%s)', $methodKey, $path, $controller, $action));
            return;
        }

        $this->registeredRoutes[$methodKey][$path] = true;
        $routes->addRoute($methodKey, $path, $handler);
    }
}
