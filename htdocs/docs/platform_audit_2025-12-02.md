# Plataforma AVP – Auditoria 02/12/2025

## 1. Escopo da varredura
- Repositório completo em `htdocs/` percorrido por módulos principais (CRM, Financeiro, Marketing, Agenda, Chat, Config, Templates).
- Inspeção dos diretórios `app/Controllers`, `app/Repositories`, `app/Services`, `resources/views`, `config/`, `public/css` e `docs/`.
- Revisão transversal de permissões (`App/Auth`), tema global (`resources/views/layouts/main.php`), roteamento (`app/Kernel.php`) e documentação existente (`docs/*.md`).
- Execução do teste mais recente (`php tests/Marketing/MarketingContactImportServiceTest.php`) para validar o marketing importer.

## 2. Principais achados e falhas
### 2.1 Arquitetura & qualidade
- **Cobertura de testes baixa**: apenas finanças (review service) e marketing importer têm testes; nenhum cenário cobre CRM, agenda, chat, templates ou finanças (fluxo de caixa principal).
- **Serviços com dependências rígidas**: vários services instanciam repositórios internamente (ex.: `MarketingContactImportService` antes do refactor), dificultando mock/memória.
- **Falta de validação padronizada**: controladores duplicam lógica de sanitização/flash (listas, segmentos, finanças). Um `FormRequest` ou helper central evitaria divergências.
- **Tratamento de uploads** duplicado (`FinanceImportController::persistUploadedFile` vs marketing import). Risco de inconsistência em limites/erros.

### 2.2 Permissões & segurança
- **Permissão declarativa inexistente**: não há matriz única (`config/permissions.php`). Permissões são strings soltas na UI/rotas (ex.: `'finance.accounts'`, `'marketing.lists'`). Falta documentação de quais papéis recebem quais escopos.
- **Rotas críticas sem guardas finos**: Kernel registra dezenas de rotas `POST` sem middleware granular além de `AuthGuard`; não há rate-limit nem logs dedicados.
- **Fluxos bloqueados por falta de permissão default**: usuário não-admin sem `dashboard.overview` fica preso em splash; precisamos perfil "Operacional" mínimo com `dashboard.overview`, `crm.overview`, `marketing.lists` e `finance.overview`.

### 2.3 UX & feedback
- **Múltiplos sistemas de flash**: marketing usa `$_SESSION['marketing_lists_feedback']`, finance usa `finance_imports_feedback`, etc. Ausência de componente global de alertas (snackbar/toast).
- **Botões sem estado**: formulários longos (finance import, marketing import) não exibem spinner/disabled, deixando dúvidas após clique.
- **Modalidade "click to show" solicitada**: alguns cards (listas) já exibem feedback ao clicar, mas demais módulos continuam estáticos; precisamos unificar comportamento de "click revela painel".

### 2.4 Visual / tema
- **`finance.css` legado**: stylesheet separado não usa variáveis do tema definido em `layouts/main.php`, resultando em campos "cinza" e tipografia desajustada.
- **Componentes antigos em CRM/Financeiro**: tabelas com borda padrão, sem gradiente/pill. Diverge da estética futurista aplicada em Marketing.
- **Assets espalhados**: não há `resources/css` com build; CSS inline em views torna difícil manter consistência.

### 2.5 Documentação & processos
- `docs/` contém planos específicos (finance importer, marketing importer) mas não há **manual operacional** consolidado (onboarding, permissões, fluxo de releases).
- Procedimentos de deploy/testes inexistentes; não há `CONTRIBUTING.md` ou checklists.
- Logs de mudanças recentes não foram conectados ao README.

## 3. Sugestões de melhorias e funções adicionáveis
1. **Central de permissões**: criar `config/permissions.php` + seeds para papéis (Admin, Operacional, Marketing, Financeiro). Incluir comando `php scripts/permissions_sync.php` para aplicar.
2. **Feedback global (HUD)**: componente Blade/partial que lê `$_SESSION['flash']` genérico e mostra toast com timer, acionado por JS ao clicar/submit.
3. **Componente de upload reutilizável**: service + trait para controllers (`HandlesUploads`) padronizando limites, diretórios e mensagens.
4. **Painel "Insights"**: adicionar cards com métricas cruzadas (finance vs marketing) reutilizando repositórios existentes.
5. **Dark-theme tokens unificados**: mover CSS repetido para `resources/css/theme.css` e importar em todas as views.
6. **CLI "doctor"**: script que valida prerequisites (extensões PHP, permissões de pasta, versão composer) antes de cada deploy.

## 4. Permissões & UX – plano imediato
- **Mapa rápido**: levantar todas as `permission` strings (grep em `layouts/main.php`, `Kernel`, controllers) e documentar matriz (permissão x módulo x escopo).
- **Liberar fluxos básicos**: atualizar `SessionAuthService::bootstrapPermissions` (ou onde o user é criado) para conceder pacote mínimo a novos usuários.
- **Click feedback**: adicionar atributo `data-feedback-target` nos botões principais e JS que injeta spinner/texto "Processando…" até resposta.

## 5. Visual futurista – roteiro
1. **Inventário UI**: capturar screens das telas financeiras (finance/index, finance/accounts, finance/imports) e registrar componentes que divergem do tema.
2. **Tokenização**: extrair cores/tipografia do layout principal e aplicá-las em um novo `resources/css/finance-modern.css` (ou migrar para Tailwind-like utility).
3. **Component library**: definir classes reutilizáveis (cards, pills, tables, buttons) e substituir markup antigo gradualmente.
4. **QA**: validar responsividade e contraste após cada módulo convertido.

## 6. Documentação & procedimentos
- Criar `docs/manual_operacional.md` com: perfis/permissões, onboarding, rotina diária (financeiro, marketing), execução de importadores, templates de comunicação.
- Atualizar `README.md` com seção "Fluxo de build/test" (composer install, scripts/migrate.php, testes recomendados).
- Adicionar `docs/release_checklist.md` cobrindo backup, migrações, smoke tests, comunicação.

## 7. Pendências + plano de ação
| Prioridade | Item | Status atual | Próximo passo |
| --- | --- | --- | --- |
| Alta | Matriz de permissões centralizada | inexistente | Criar `config/permissions.php`, ajustar criação de usuário para usar perfis.
| Alta | Padronizar feedback visual | múltiplos flashes desconexos | Implementar componente toast global + JS spinner.
| Alta | UI Financeiro alinhada ao tema | usa `css/finance.css` legado | Reescrever CSS com tokens do ThemePreset e atualizar views financeiras.
| Média | Documentação operacional | planos isolados em `docs/` | Consolidar manual + checklist release.
| Média | Testes adicionais | apenas 2 suites | Mapear serviços críticos e adicionar pelo menos smoke tests (CRM, Agenda, Finance dashboards).
| Média | Tratamento unificado de upload | duplicado | Extrair trait/serviço `HandlesUploads` e usar em Finance + Marketing.
| Baixa | Painel insights cruzado | inexistente | Reaproveitar repositórios para cards (ex.: conversão marketing -> vendas).
| Baixa | Script "doctor" | inexistente | Criar script CLI para checar extensões, permissões de pasta.

---
Documento serve como referência inicial. Próximos commits devem endereçar cada item conforme prioridade definida acima.
