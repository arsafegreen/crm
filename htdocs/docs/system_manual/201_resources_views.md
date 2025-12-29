# 201 – Views (`resources/views`)

O layout principal (`layouts/main.php`) define o shell do painel: tema, navegação por seções, componentes de user-menu e avisos de idle. Views específicas são PHP puro renderizados via helper `view()` (com extração de variáveis).

## Layouts

| Arquivo | Uso |
| --- | --- |
| `layouts/main.php` | Painel autenticado: inclui nav, user menu, flores neon, tokens CSS. Valida permissões para exibir links. |
| `layouts/auth.php` | Telas de login/registro/TOTP. Estrutura minimalista com `.auth-shell`. |
| `layouts/public_marketing.php` | Centro de preferências público – branding escuro e CTA para baixar logs. |

## Módulos Principais

- `dashboard/` – cards resumidos de CRM, finance e alertas.
- `crm/` – telas de clientes, protocolos, importação, parceiros (subpastas `clients.php`, `partners.php`, etc.).
- `marketing/` – listas, segmentos, contas de e-mail, forms.
- `templates/` – biblioteca de templates, formulário de criação/edição.
- `finance/` – páginas placeholder para overview/calendário/contas (serão detalhadas com grids e filtros conforme módulo evoluir).
- `public/` – `preferences.php` e `preferences_invalid.php` (centro LGPD).

Cada view deve ser documentada com:
1. Variáveis esperadas (`$lists`, `$segments`, `$feedback`).
2. Componentes principais (cards, tabelas, forms, modais).
3. Interações JS (quando aplicável).

> **Checklist**: ao alterar uma view relevante, atualize este capítulo indicando quais variáveis e seções foram adicionadas.
