# Roadmap Financeiro & Marketing (Semana 1)

## 1. Módulo Financeiro – Imersão e Arquitetura

### 1.1 Objetivos
- Disponibilizar visão executiva (Fluxo de Caixa e DRE) com drill-down até lançamentos.
- Controlar contas a pagar/receber, impostos e obrigações de forma integrada ao CRM.
- Automatizar cobranças e conciliações, reduzindo lançamento manual.

### 1.2 Entidades e Relacionamentos Iniciais
- `FinancialAccount`: contas bancárias/caixa, com saldo diário projetado.
- `CostCenter`: centros/projetos para rateio.
- `Party`: fornecedores, clientes e parceiros (reuso do CRM onde possível).
- `Invoice` (payable/receivable): ciclo completo com recorrência e anexos.
- `Transaction`: lançamentos conciliados (importados manualmente ou via integração CNAB/PIX).
- `TaxObligation`: compromissos fiscais (DARF, DAS, FGTS etc.).
- `ReminderRule`: regras de alerta (liquidez, impostos, inadimplência).

### 1.3 Fluxos Essenciais
1. **Captura de lançamentos**
   - Importação CSV/OFX/CNAB.
   - Lançamento manual guiado.
   - Integração com pipelines do CRM (conversão de propostas em faturamento).
2. **Conciliação**
   - Matching semi-automático por valor/data/descrição.
   - Sugestões de split (ex.: pagamentos parcelados).
3. **Contas a pagar/receber**
   - Agenda com filtros por status, centro de custo e tags.
   - Emissão de comprovantes e anexos obrigatórios.
4. **Régua de cobrança**
   - Sequência de lembretes (e-mail, WhatsApp, boleto).
   - Registro de acordos e renegociações.
5. **Dashboards**
   - Fluxo de caixa (diário/semanal/mensal) previsto x realizado.
   - DRE resumida com métricas CAC, margem, LTV.
   - Indicadores de liquidez e alertas automáticos.

### 1.4 Wireframes/UX Notes
- **Home Financeira**: cards de saldo por conta, alertas críticos e atalhos (novo lançamento, importar extrato, pagar guia).
- **Calendário Fiscal**: timeline com obrigações e status (gerado automaticamente conforme CNAE/UF).
- **Visão de Contas**: tabela com split por centro de custo + quick actions (aprovar, enviar cobrança, anexar recibo).

### 1.5 Backlog Inicial (Financeiro)
1. Definir modelos de dados + migrações.
2. Construir importadores (CSV/OFX) com validações.
3. CRUD contas/centros/lançamentos.
4. Engine de conciliação + tela de revisão.
5. Agenda de contas e alertas.
6. Dashboards e relatórios.

## 2. Marketing Digital – Motor de E-mail em Massa

### 2.1 Objetivos
- Centralizar disparos em alta escala com rastreabilidade e reputação controlada.
- Disponibilizar segmentação dinâmica e automações multietapa.
- Garantir compliance LGPD (opt-in/out, consentimento, auditoria).

### 2.2 Componentes Técnicos
- **Mail Transport Layer**: adaptadores SMTP, APIs (SES, Sendgrid) e fallback local.
- **Mail Queue Service**: fila distribuída com throttling por provedor/domínio.
- **Template Builder**: editor drag & drop (MJML/HTML) com biblioteca modular.
- **Tracking & Metrics**: webhooks de entrega/abertura/clique/bounce + pixel.
- **Compliance & Consent**: registro de double opt-in, centro de preferências, assinatura digital dos termos.
- **Event Bridge**: capta eventos do CRM/chat (lead criado, certificado emitido) e alimenta jornadas.

### 2.3 Dados e Segmentação
- `AudienceList`: listas principais com metadata (origem, finalidade, LGPD).
- `Contact`: reuso da base CRM + atributos dinâmicos (score, tags comportamentais).
- `Segment`: filtros salvos (campos, interações, eventos de produtos).
- `Campaign`: disparos pontuais com logs de status.
- `Journey`: automações com nós (start, delay, condition, action).
- `DeliveryLog`: rastreio completo (provedor, IP, métricas, feedback loops).

### 2.4 Fluxos Essenciais
1. **Importação de contatos** (CSV, integrações, webhooks) com dedupe.
2. **Gestão de consentimento** (double opt-in, opt-down por categoria).
3. **Criação de campanhas** com pré-visualização, teste A/B e checklist de reputação.
4. **Envio escalável** com monitoramento de fila, retry inteligente e limites por sender.
5. **Relatórios**: heatmaps, funil (enviado/entregue/aberto/clicado), exportação.
6. **Automações**: jornadas visuais com condições (tag, comportamento, atributos financeiros).

### 2.5 Backlog Inicial (Marketing)
1. Modelagem de listas/contatos/segmentos.
2. Setup da fila e conectores SMTP/SES.
3. CRUD + importação/exportação de contatos.
4. Editor de templates e biblioteca inicial.
5. Disparo de campanhas com monitor de status.
6. Relatórios em tempo real e centro de preferências.

## 3. Cronograma Prioritário (6 Semanas)

| Semana | Financeiro | Marketing |
| --- | --- | --- |
| 1 | Modelos de dados, wireframes, importador CSV básico, backlog aprovado. | Arquitetura do motor, esquema de listas/segmentos, política LGPD. |
| 2 | CRUD contas/centros/lançamentos, importador OFX/planilhas. | Cadastro/listas/segmentos + importador com dedupe. |
| 3 | Engine de conciliação + tela de revisão, automação de recorrências. | Editor de templates e pré-visualização + testes A/B. |
| 4 | Dashboards (fluxo de caixa, DRE) + alertas de liquidez. | Motor de envio + monitor de fila + métricas de entrega. |
| 5 | Agenda fiscal, contas a pagar/receber integradas ao CRM, régua de cobrança. | Automações e jornadas visuais + centro de preferências. |
| 6 | Hardening, permissões, integrações bancárias extras e QA. | Reporting avançado, exportações, reputação/IP pool, QA. |

### Próximos Passos
1. Validar este documento com stakeholders e ajustar prioridades semana 1.
2. Criar tickets técnicos a partir dos itens dos backlogs.
3. Iniciar implementação dos modelos/migrações (financeiro) e da fila de e-mails (marketing).
