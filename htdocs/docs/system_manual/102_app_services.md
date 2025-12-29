# 102 – Services (`app/Services`)

Services encapsulam regras de negócio e integrações externas. A convenção é injetar Repositories via construtor (com fallback para instâncias padrão) e expor métodos explícitos para cada caso de uso.

## Catálogo de Services

| Serviço | Papel | Dependências principais |
| --- | --- | --- |
| `AppointmentService.php` | Cria/atualiza compromissos, valida disponibilidade e dispara notificações. | `AppointmentRepository`, `CalendarPermissionRepository`. |
| `AvpAvailabilityService.php` | Calcula janelas disponíveis para agendamentos AVP (certificados). | Configs da agenda + `AppointmentRepository`. |
| `BaseRfbImportService.php` | Pipe de importação da base RFB (zip/csv). | Manipula arquivos em `storage/uploads`, chama Repositories de RFB. |
| `ChatService.php` | Cérebro do chat: threads, mensagens, integrações externas e tokens públicos. | `ChatThreadRepository`, `ChatMessageRepository`, `ChatExternalLeadRepository`. |
| `EmailAccountService.php` | CRUD e validação de contas SMTP usadas pelo motor de e-mail. | `EmailAccountRepository`, `SettingRepository`, secrets criptografados. |
| `MaintenanceService.php` | Rotinas de manutenção (limpeza de cache, resets, etc.). | `Storage` + scripts. |
| `SocialAccountService.php` | Cadastro de tokens de redes sociais/WhatsApp. | `SocialAccountRepository`, encriptação de credenciais. |

### Subpastas

- `Finance/` – futuros serviços para contas, lançamentos, conciliação (ainda em scaffolding).
- `Import/` – auxiliares para parsing/validação de arquivos (ex.: `GenericCsvImportService`).
- `Mail/`
  - `MimeMessageBuilder.php`: monta mensagens multipart (texto e HTML) seguindo RFC 2045. Utilizado pelo worker SMTP.
  - `SmtpMailer.php`: implementação direta de SMTP com STARTTLS/SSL, autenticação LOGIN e logs detalhados.
- `Marketing/`
  - `ConsentService.php`: garante tokens de preferência, aplica toggles nas categorias definidas em `config/marketing.php`, executa opt-out global e registra eventos em `mail_delivery_logs` via `MailDeliveryLogRepository`.
- `Security/`
  - `TokenSigner.php`, `EncryptionService.php`, etc., responsáveis por hashes seguros, criptografia simétrica e geração de tokens temporários.

## Boas Práticas

1. **Não** acessar `$_POST`/`$_SESSION` diretamente dentro de Services.
2. Serviços devem ser unit-test friendly: manter dependências explícitas e evitar chamadas estáticas globais.
3. Ao criar um serviço novo, registrar sua descrição neste capítulo e referenciar os arquivos relacionados no capítulo de Controllers/Repositórios.
