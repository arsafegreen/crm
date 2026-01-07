# Planejamento e lógica do sistema Network (isolado)

Visão profissional para operar como vitrine/network de empresários em XAMPP compartilhado, mas isolado de outros sistemas.

## Objetivos de arquitetura
- Isolamento: app dedicado em `network_site`, DB próprio `network_*`, sem compartilhar sessões ou libs externas.
- Simplicidade operável: PHP puro + PDO MySQL, sem dependências adicionais, focado em robustez.
- Segurança: CSRF em todas as ações, sessões protegidas, hashes Argon2ID, entradas saneadas.
- Observabilidade mínima: registrar ações críticas de admin e rejeições de validação.

## Fluxos principais (lógica atual)
1) Landing `/`: consulta `network_ads` (ativos por janela de tempo), mostra banners e CTA para `/network`.
2) Formulário `/network` → POST `/network/lead`: sanitiza/valida, rate-limit por sessão (20s), gera payload com grupos sugeridos, grava em `network_leads` (JSONs de grupos, CNPJs, áreas, political_access).
3) Autenticação `/network/admin/login`: email/senha Argon2ID, guarda `admin_id/name` em sessão; acesso requerido para painel e APIs.
4) Painel `/network/admin`: carrega leads/anúncios via fetch:
   - Leads: `/network/api/leads` (limite 500) → permite aprovar/negar com nota e definir grupos.
   - Anúncios: `/network/api/ads` CRUD (create/edit/toggle).
5) Logout `/network/admin/logout`: POST com CSRF limpa sessão.

## Requisitos novos (login, segurança e cadastro de usuários)
- Login seguro: exigir senha com letras+números+caractere especial; 5 erros bloqueiam a conta até liberação manual pelo admin; captcha “não sou robô” (desafio de imagens/honeypot) no login.
- Recuperação de senha: fluxo de “esqueci” com token de reset (expira), protegido por captcha; notificar admin ao bloquear conta por tentativas.
- Cadastro de usuários (separar PF/PJ):
  - PF: nome, CPF, data de nascimento, e-mail, telefone, endereço.
  - PJ: nome, CPF do responsável, CNPJ (1+), data de nascimento do responsável, e-mail, telefone, endereço.
  - Unicidade: CPF, CNPJ, e-mail e telefone são únicos; um CPF pode ter múltiplos CNPJs vinculados.
- Anti-abuso: limitar tentativas por IP/UA, registrar falhas, aplicar cooldown progressivo; CSRF em todos os formulários.

## Riscos e reforços técnicos planejados
- Sessão: regenerar ID no login; setar cookies com `httponly`, `samesite=lax`, `secure` quando HTTPS.
- Validação: CPF/CNPJ com dígito verificador; telefone com mínimo de dígitos; clamp de tamanho em texto; normalizar datas.
- Abuso: rate-limit por IP/UA além da sessão; anti-robot simples (captcha leve/honeypot).
- APIs: paginação e filtros (status, intervalo de datas, região/área) para leads; limitar tamanho de resposta.
- Índices: adicionar índices para filtros (region, area, status+created_at); avaliar índice composto para busca por email/telefone.
- Auditoria: nova tabela `network_audit_logs` (admin_id, ação, alvo, payload, ip, ua, created_at).
- Headers: adicionar CSP básica, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`; HSTS se HTTPS.
- Config: mover segredos para env/ini fora do repo; permitir DSN via variáveis; opcional pool persistente.

## Backlog proposto (ordem sugerida)
1) Segurança de sessão + cookies e regeneração.
2) Validações fortes (CPF/CNPJ/telefone/limites de texto/data).
3) Paginação/filtros na API de leads e ajustes no painel para consumir.
4) Índices extras e, se necessário, paginação incremental (cursor/offset).
5) Auditoria de ações admin (banco) e exibição básica no painel.
6) Melhor UX no painel (modais/feedback em vez de prompt, mostrar notas/decisor).
7) Hardening HTTP (CSP/headers), revisão de URLs de imagem (apenas https/domínios permitidos).
8) Automatizar seeds/config: `.env` exemplo, script de migração para novos índices/tabelas.

## Implementado agora
- Fluxo de login público PF/PJ com captcha de imagem, bloqueio após 5 falhas, senha forte e regen de sessão.
- Cadastro PF/PJ com validação de CPF/CNPJ/telefone/data, unicidade de CPF/CNPJ/e-mail/telefone e suporte a múltiplos CNPJs por CPF.
- Recuperação de senha com token (tabela `network_password_resets`), captcha e log de links em `storage/reset-links.log`; falhas zeradas após reset.
- Tabelas novas: `network_accounts`, `network_account_cnpjs`, `network_password_resets`, `network_audit_logs`; colunas de falha/bloqueio em `network_admins`.
- Login admin protegido com captcha, bloqueio por 5 tentativas e registro de auditoria em caso de lock.
- Perfil completo pós-login (segmento, CNAE, porte, faturamento, região/UF/cidade, objetivos, canais, posição política) com salvamento e validação.
- Grupos automáticos: geral, política (esquerda/direita/neutro), região (UF), atividade, atividade-única (capacidade 1 com criação incremental) e objetivos.
- Mensageria própria 1:1 com controle político (esquerda/direita isolados, neutro enxerga ambos), rate-limit (10/min) e auditoria; tabelas `network_groups`, `network_group_members`, `network_messages`.
- Visão de grupos do usuário (`/network/groups` + API) e endpoints admin para listar contas, mudar posição política e destravar bloqueios.
