# 103 – Repositories (`app/Repositories`)

Repositories encapsulam queries SQL sobre o SQLite local. Todos utilizam `App\Database\Connection::instance()` e retornam arrays associativos.

## Diretório Raiz

| Arquivo | Descrição |
| --- | --- |
| `AppointmentRepository.php` | CRUD de compromissos; usado pela agenda e serviços AVP. |
| `AvpAccessRepository.php` | Controle de acessos para parceiros AVP. |
| `AvpScheduleRepository.php` | Configurações de agenda AVP (faixas, pausas). |
| `CalendarPermissionRepository.php` | Permissões específicas da agenda. |
| `CampaignRepository.php` / `CampaignMessageRepository.php` | Persistem campanhas e mensagens individuais; expõem helpers `createWithMessages`, `lockByStatus`, etc. |
| `CertificateRepository.php` / `CertificateAccessRequestRepository.php` | Dados de certificados digitais e solicitações. |
| `ChatThreadRepository.php`, `ChatMessageRepository.php`, `ChatExternalLeadRepository.php` | Estruturas do chat interno/externo (threads, mensagens, leads públicos). |
| `ClientRepository.php` e auxiliares (`ClientProtocolRepository.php`, `ClientActionMarkRepository.php`, `ClientStageHistoryRepository.php`) | Gestão completa do CRM. |
| `EmailAccountRepository.php` | Persiste contas de e-mail com campos seguros (host, porta, auth, warmup). |
| `ImportLogRepository.php` | Histórico de importações (financeiro/CRM). |
| `PartnerRepository.php`, `PartnerIndicationRepository.php` | Parceiros/indicações. |
| `PipelineRepository.php` | Etapas de pipeline comercial. |
| `RfbProspectRepository.php` | Leads vindos da base RFB. |
| `SettingRepository.php` | Key-value para configurações do painel. |
| `SocialAccountRepository.php` | Tokens de redes sociais. |
| `SystemReleaseRepository.php` | Controle de releases/aplicações de patch. |
| `TemplateRepository.php` | Controle de templates e versões; cria tabelas se não existirem. |
| `UserRepository.php`, `UserDeviceRepository.php` | Autenticação e devices confiáveis. |

## Subpastas

### `Finance/`
Contém scaffolding para entidades financeiras (accounts, transactions, tax obligations). À medida que o módulo avançar, registrar cada arquivo aqui com explicação das colunas e relacionamentos.

### `Marketing/`
| Arquivo | Descrição |
| --- | --- |
| `AudienceListRepository.php` | CRUD de listas, anexação/desinscrição de contatos (inclui `unsubscribeContactEverywhere`). |
| `MarketingContactRepository.php` | Base de contatos marketing, normalização de e-mail, métodos de consentimento (opt-in/out, tokens). |
| `ContactAttributeRepository.php` | (Novo) Upsert de atributos dinâmicos ligados ao consent center. |
| `SegmentRepository.php` | Segmentos dinâmicos com contagem de contatos. |
| `MailQueueRepository.php` | Persistência da fila `mail_queue_jobs`, claiming de jobs e integração com `MailDeliveryLogRepository`. |
| `MailDeliveryLogRepository.php` | Registro e leitura de eventos de entrega/consentimento (JSON). |
| `JourneyRepository.php` | (Planejado) fluxo de automações multi-etapa. |

## Convenções de Código

- Métodos `create/update/delete` retornam `int` ou `void` – nunca Responses.
- Todas as queries devem usar parâmetros nomeados (`:id`, `:email`).
- Para colunas JSON, utilize `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` antes de persistir.
- Ao adicionar uma tabela nova, gerar migração correspondente em `app/Database/Migrations` e registrar o repositório aqui.
