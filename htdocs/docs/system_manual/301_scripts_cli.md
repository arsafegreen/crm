# 301 – Scripts CLI (`scripts/`)

Scripts são executados via `php scripts/<path>.php` e utilizam apenas o autoloader da aplicação. Mantemos 3 categorias principais:

## 1. Infraestrutura

| Script | Função |
| --- | --- |
| `migrate.php` | Executa todas as migrações em `app/Database/Migrations`. Aceita `--path=` para rodar arquivo específico. |
| `seed.php` (quando presente) | Popular dados de demo. |
| `performance/cache_crm_clients.php` | Gera caches para relatórios de CRM. |

## 2. Marketing

| Script | Função | Parâmetros |
| --- | --- | --- |
| `marketing/enqueue_campaign_messages.php` | Move registros de `campaign_messages` para `mail_queue_jobs`, travando versão de template e status. | `--limit`, `--campaign-id`, `--dry-run`. |
| `marketing/deliver_mail_queue.php` | Worker SMTP: reclama jobs, monta MIME via `MimeMessageBuilder` e envia com `SmtpMailer`. | `--worker`, `--batch`, `--sleep`, `--account-id`, `--log`, `--once`. |

## 3. Financeiro & Importadores

| Script | Função |
| --- | --- |
| `finance/import_transactions.php` (planejado) | Consumirá CSV/OFX usando serviços em `app/Services/Finance`. |
| `import/rfb_sync.php` | Automatiza ingestão da base RFB (usa `BaseRfbImportService`). |

Ao criar um script:
1. Coloque namespace `App\Scripts\...` se necessário.
2. Documente parâmetros esperados e exemplos de uso nesta tabela.
3. Se o script impactar dados regulados (LGPD/financeiro), adicionar seção de observabilidade (logs/emails).
