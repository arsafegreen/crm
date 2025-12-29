# 101 – Controllers (`app/Controllers`)

Este capítulo descreve cada controlador HTTP responsável por receber as requisições roteadas pelo `Kernel`. Todos estendem PHP puro, utilizando `Symfony\Component\HttpFoundation\Request/Response` e helpers globais (`view()`, `json_response()`).

## Estrutura Geral

- Cada método público corresponde a uma rota declarada em `app/Kernel.php`.
- Controllers só devem orquestrar validações e chamar Services/Repositories.
- Mensagens para a UI usam `$_SESSION` como flash (ex.: `$_SESSION['campaign_feedback']`).

## Controllers Principais

| Arquivo | Responsabilidade | Observações |
| --- | --- | --- |
| `DashboardController.php` | Renderiza o painel principal (`/`). Consolida métricas básicas de CRM/financeiro. | Depende de repositórios agregadores (clientes, certificados, agenda). |
| `AutomationController.php` | Endpoint `POST /automation/start` para iniciar rotinas backend (ex.: robôs). | Retorna JSON simples, usa `AuthGuard` para exigir permissão `automation.control`. |
| `AgendaController.php` | CRUD de config e compromissos da agenda operacional. | Interage com `AppointmentService`/`AppointmentRepository`. Possui rotas POST protegidas por CSRF. |
| `AuthController.php` | Fluxo completo de autenticação (login, registro, TOTP, logout). | Usa `SessionAuthService`; métodos públicos listados no `AuthGuard` como rotas abertas. |
| `CampaignController.php` | Assistente para campanhas de e-mail mensais. | Monta listas a partir de `ClientRepository`, persiste `CampaignRepository` e `CampaignMessageRepository`. |
| `ChatController.php` | UI de chat interno e atendimentos externos. | Roteia threads, mensagens, grupos e endpoints externos (`/chat/external-thread/...`). Depende de `ChatService`. |
| `ConfigController.php` | Painel "Configurações" – email, tema, segurança, importações, releases. | Possui muitos métodos POST realizando updates em `SettingRepository` e serviços auxiliares. |
| `CrmController.php` | CRUD de clientes, protocolos, importação e operações da carteira. | Reutiliza `ClientRepository`, `CertificateRepository`, `PartnerRepository`. |
| `FinanceController.php` | Telas financeiras (overview, calendário, contas, transações). | Hoje serve layout e forms; futuramente conectará os repositórios `Finance\*`. |
| `FinanceImportController.php` | Gestão dos lotes de importação financeira (CSV/OFX). | CRUD básico dos lotes, reprocessamento, cancelamento e import step-by-step. |
| `MarketingController.php` | UI de listas/segmentos/contas de envio. | Usa `AudienceListRepository`, `SegmentRepository`, `EmailAccountService`. Valida formulários e aplica flash feedback. |
| `MarketingConsentController.php` | Centro público de preferências (`/preferences/{token}`). | Chama `Marketing\ConsentService` para confirmar opt-in, atualizar toggles e exportar logs. |
| `PartnerController.php` | Cadastro e vínculo de parceiros/contadores. | Depende de `PartnerRepository` e integra com CRM. |
| `ProfileController.php` | Atualização de perfil e senha do usuário logado. | Obriga atualização quando `SessionAuthService::passwordRequiresChange()` retorna true. |
| `RfbBaseController.php` | Consulta base RFB (receita). | Oferece filtros por cidade/CNAE, atualização de status/contato. |
| `SocialAccountController.php` | Cadastro de contas sociais para integrações. | Usa `SocialAccountService`. |
| `TemplateController.php` | CRUD de templates e versões. | Depende de `TemplateRepository`, gera flash messages e view de preview. |
| `Admin/AccessRequestController.php` | Aprovação de acessos, permissões e resets administrativos. | Exige usuário admin (validado no `AuthGuard`). |
| `Admin/ChatAdminController.php` | Configurações avançadas do chat (purge, policy). | Também limitado a administradores. |

## Fluxo de Manutenção

1. **Adicionar rota** em `Kernel.php` → amarrar a novo método público.
2. **Criar teste manual** (curl/browser) e atualizar manual deste capítulo com o novo controller/método.
3. **Atualizar manual**: incluir descrição na tabela acima e, se relevante, apontar dependências cruzadas.
