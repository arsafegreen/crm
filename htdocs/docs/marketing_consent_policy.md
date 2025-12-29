# MKT-03 – Política de Consentimento & Termos

## 1. Objetivos
- Garantir conformidade com LGPD e boas práticas de reputação para disparos de e-mail/SMS/WhatsApp.
- Registrar consentimentos com trilha de auditoria e revogação granular.
- Oferecer comunicações de confirmação (double opt-in) e páginas de preferências alinhadas ao branding da Marketing Suite.

## 2. Bases Legais e Categorias de Consentimento
| Categoria | Base Legal | Exemplos | Política de Retenção |
| --- | --- | --- | --- |
| **Comunicados transacionais** | Execução de contrato | confirmação de agendamento, status de certificado | Retenção enquanto o contrato estiver ativo + 5 anos |
| **Campanhas comerciais** | Consentimento expresso | promoções, upsell AVP, eventos | Revoga imediatamente; logs mantidos por 5 anos |
| **Pesquisa/NPS** | Legítimo interesse + opt-out claro | pesquisa de satisfação pós-atendimento | 2 anos |
| **Alertas críticos** | Obrigação legal | comunicações sobre segurança/conta | Até cumprimento da obrigação |

## 3. Fluxos de Consentimento
1. **Double Opt-In padrão**
   - Usuário envia formulário (landing, chat, import).
   - Registro inicial em `marketing_contacts` com `consent_status = pending`.
   - Disparo automático via `mail_queue_jobs` usando template `consent_confirm_email_v1`.
   - Link contém token assinado (`?token=...`) que atualiza `consent_status = confirmed`, `consent_at = now()` e registra evento em `journey_enrollments` (se houver jornada).
2. **Opt-down granular**
   - Página `/preferences/{hash}` lista categorias (Campanhas, Estudos de caso, Eventos locais, Avisos AVP).
   - Cada toggle grava em `marketing_contact_attributes` (`pref_campaigns = false`, etc.).
   - Ao desligar tudo, `consent_status = opted_out`, `opt_out_at` preenchido, `audience_list_contacts.subscription_status = unsubscribed`.
3. **Importações externas**
   - CSV deve incluir coluna `consent_source` e `consent_at`.
   - Serviço de importação valida evidências; quando ausentes, marca como `pending` e envia e-mail de confirmação automático.

## 4. Templates Propostos
### 4.1 E-mail Double Opt-In (HTML simplificado)
```
Assunto: Confirme seu cadastro na Marketing Suite

Olá {{first_name | default:""}},

Recebemos seu pedido para receber novidades sobre certificados digitais.
Clique no botão abaixo para confirmar:
[Confirmar assinatura]({{confirmation_url}})

Se não foi você, ignore este e-mail.
```

### 4.2 Página de Preferências
- Cabeçalho com branding + status atual (ex.: “Você está inscrito em: Campanhas comerciais, Eventos”).
- Cards por categoria com toggle + descrição curta + link “Saiba por que enviamos isso”.
- CTA secundário “Baixar registros de consentimento” (gera JSON com histórico de `mail_delivery_logs`).
- Implementação atual: rota pública `/preferences/{token}` usando o `preferences_token` de `marketing_contacts`. O link aceita `?confirm=1` para concluir o double opt-in e expõe botão para baixar o JSON mencionado acima.

### 4.3 Texto de Termos
> "Autorizo o envio de comunicações relacionadas a campanhas, eventos e conteúdos educacionais da {{company_name}}, podendo revogar esta autorização a qualquer momento pelos canais informados ao final de cada mensagem."

## 5. Auditoria e Logs
- Cada mudança de status em `marketing_contacts` gera evento em `mail_delivery_logs` (tipo `consent_update`) vinculado ao ID do contato.
- Revisão trimestral: exportar `consent_status`, `opt_out_at`, `complaint_count` para o jurídico/compliance.
- Guardar evidências (IP, user-agent, timestamp) no campo `metadata` de `audience_list_contacts`.

## 6. Integrações Pendentes
- Webhook com provedores (SES/Sendgrid) para mapear `complaint/bounce` → `markOptOut` automaticamente.
- Endpoint `/api/v1/consent/logs` protegido por token de auditoria para baixar histórico.
- Widget de preferências embutível em páginas externas (iframe com token temporal).

## 7. Próximos Passos
1. Implementar templates de e-mail/página referenciando os textos acima.
2. Configurar serviço que consome `mail_queue_jobs` para enviar confirmação imediata.
3. Criar testes unitários de `MarketingContactRepository::recordConsent` cobrindo fluxos opt-in/out, inclusive importações.
