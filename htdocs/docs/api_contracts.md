# Contratos de APIs internas (v1)

Visão geral dos blocos, base path e operações principais. Interfaces em PHP existem em `app/Contracts/*ServiceInterface.php`; os endpoints HTTP devem seguir o mesmo contrato quando expostos.

## Customer Core
- Base: `/api/v1/customers`
- `GET /lookup?document|phone|email` → cliente básico `{id, document, name, emails[], phones[], status, created_at, updated_at}`
- `POST /lookup/batch` body: `[ {document?, phone?, email?}, ... ]` → array de clientes/null
- `POST /upsert` body: `{document, name?, email?, phone?, whatsapp?, extra?}` → `{id}`
- `GET /{id}`, `PATCH /{id}`
- Escopos: `customers.read`, `customers.write`

## Certificados (inclui Resumo/Dashboard)
- Base: `/api/v1/certificates`
- `GET /latest?customer_id=` → certificado mais recente
- `GET /customers/{customer_id}` → lista de certificados
- `POST /issue` / `POST /renew` body: `{customer_id, protocol, start_at?, end_at?, status?, is_revoked?, partner_accountant?, partner_accountant_plus?, avp_*?, source_payload?}` → `{id}`
- `GET /stats/partners?start_at&end_at&sort=&direction=&limit=`
- Escopos: `certificates.read`, `certificates.write`

## Financeiro
- Base: `/api/v1/finance`
- `POST /parties/ensure` body: `{customer_id}` → `{party_id}`
- `POST /invoices` body: `{party_id, items:[{description, amount_cents, quantity?, cost_center_id?, tax_code?, meta?}], due_date?, meta?}` → `{invoice_id}`
- `GET /invoices/{id}` / `PATCH /invoices/{id}` (status, pagamento)
- Escopos: `finance.read`, `finance.write`

## Dados Públicos (RFB)
- Base: `/api/v1/public-data`
- `GET /rfb?document=` → dados cadastrais
- `POST /rfb/refresh` body: `{document}` → enfileira/atualiza
- Escopos: `publicdata.read`, `publicdata.write`

## Marketing
- Base: `/api/v1/marketing`
- `POST /contacts/lookup` / `POST /contacts/upsert` (contato marketing)
- `POST /campaigns/enqueue` (mensagens) / `POST /mail-queue/deliver` (worker)
- Escopos: `marketing.read`, `marketing.write`

## WhatsApp/Chat
- Base: `/api/v1/whatsapp` e `/api/v1/chatbot`
- `POST /threads` / `POST /messages` (envio) / `GET /threads/{id}`
- `POST /chatbot/suggest` body: `{customer_id?, thread_id?, last_message}` → `{suggestion, actions?}`
- `POST /chatbot/actions` body: `{action: \"schedule|update_customer|issue_certificate\", payload}` (delegando aos demais blocos)
- Escopos: `whatsapp.read`, `whatsapp.write`, `chatbot.read`, `chatbot.write`

### Notas gerais
- Identidade: sempre referenciar `customer_id` do Customer Core; se o bloco rodar sozinho, usar `upsert` para criar/conciliar.
- Versionamento: prefixo `/v1` para evoluir sem quebrar consumidores.
- Autenticação: tokens com escopos por bloco; preferir também corrrelação `X-Request-Id`.
- Eventos (opcional): `customer.created|updated`, `certificate.issued|expired`, `finance.invoice.created|paid`, `publicdata.refresh.done` para acoplamento mais solto.
