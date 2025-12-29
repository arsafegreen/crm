# Semana 1 – Backlog Detalhado

## Financeiro
1. **FIN-01 – Modelagem de dados e migrações**
   - Entities: FinancialAccount, CostCenter, Party, Invoice, Transaction, TaxObligation, ReminderRule.
   - Entregáveis: migrations, seed básico de centros, documentação ER.
2. **FIN-02 – Importador CSV básico**
   - Upload manual, mapeamento de colunas, validações (datas, valores, conta destino).
   - Persistência temporária + job de processamento assíncrono.
3. **FIN-03 – Wireframes e protótipos**
   - Home Financeira, Calendário Fiscal, Visão de Contas.
   - Exportar para Figma/PNG e anexar ao repositório.

## Marketing Digital (E-mail)
1. **MKT-01 – Infraestrutura da fila de envio**
   - Escolher stack (ex.: Redis + workers PHP) e definir throttling/config por provedor.
   - Criar tabelas de jobs, logs e configurações de provedores.
2. **MKT-02 – Modelagem de listas/segmentos/contatos**
   - Migrar estrutura para tabelas `audience_lists`, `contacts`, `contact_attributes`, `segments`.
   - Inclui endpoints CRUD e validações LGPD (opt-in obrigatório).
3. **MKT-03 – Política de consentimento e termos**
   - Definir textos padrão, fluxo de double opt-in e registro de auditoria.
   - Criar templates de e-mail de confirmação e páginas de preferências (rascunho).

## Dependências & Notas
- Verificar disponibilidade de provedores (SES, Sendgrid) antes de implementar conectores.
- Confirmar requisitos legais para armazenamento de comprovantes fiscais (Financeiro).
- Cada task deve gerar ticket no tracker interno com estimativa e responsável.
