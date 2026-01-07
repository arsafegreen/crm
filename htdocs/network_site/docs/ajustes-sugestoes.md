# Registro de ajustes e sugestões – Network

Contexto: site de network isolado no mesmo XAMPP, MySQL dedicado. Público-alvo: empresários do Brasil, com painel admin próprio. Vamos registrar aqui, em texto corrido, tudo que propusermos/ajustarmos.

## Situação atual (observado)
- App único em `index.php` com rotas públicas, formulário, APIs JSON e painel admin.
- Banco MySQL com tabelas `network_admins`, `network_leads`, `network_ads`; schema criado em runtime.
- Formulário tem CSRF + rate-limit por sessão (20s) e validações básicas; landing exibe anúncios ativos do banco; painel permite aprovar/negar leads e gerenciar anúncios.
- Config DB em `config/database.php` (padrão localhost/network).

## Sugestões prioritárias (profissional)
- Segurança de sessão: regenerar ID na autenticação do admin; ajustar cookies (`HttpOnly`, `SameSite=Lax`, expiração curta).
- Validação forte: CPF/CNPJ com dígitos verificadores; telefone com comprimento mínimo; normalizar data de nascimento; tamanho máximo em `message/skills` no backend.
- Rate-limit e abuso: limitar POST por IP/UA além de sessão; captcha leve estilo “não sou robô” (desafio de imagens ou honeypot) para login e formulários públicos.
- APIs e volume: paginar `/network/api/leads` (status, datas, limite, offset) e incluir filtros; índices adicionais (region, area, status+created_at).
- Auditoria: logar ações admin (aprovar/nega/toggle ad) com IP/UA e timestamp em tabela própria; guardar `decision_by`/nota já existe, mas falta histórico.
- Config: mover credenciais para env/.ini fora do VCS; opcional `PDO::ATTR_PERSISTENT` em ambiente estável; revisar pool/limites do MySQL.
- UI/Admin: feedback explícito nas ações (toast/alert), confirmar sucesso/erro; no grid mostrar nota/decisor/status com cor; evitar `prompt` simples para edição.
- Segurança extra: headers de segurança (CSP simples, HSTS se HTTPS, X-Frame-Options, X-Content-Type-Options), sanitização de upload de URLs de imagem (validar domínio/https).

## Requisitos novos (login/cadastro profissional)
- Login: senha com letras+ números + caractere especial (enforce no cadastro/reset); captcha “não sou robô” com seleção de imagens; 5 erros de senha → bloquear conta e notificar admin para desbloqueio manual.
- Recuperação de senha: fluxo de “esqueci” com e-mail de reset (token único expira) ou contato com admin se e-mail não validado; manter captcha no pedido.
- Cadastro novo (separar PF/PJ):
  - PF: nome, CPF, data de nascimento, e-mail, telefone, endereço.
  - PJ: nome, CPF (do responsável), CNPJ (1+), data de nascimento do responsável, e-mail, telefone, endereço.
  - CPF, CNPJ, e-mail e telefone são únicos no sistema; CPF pode ter múltiplos CNPJs vinculados.
- Controle de abuso no login/cadastro: limitar por IP/UA, recaptcha simples, bloquear após tentativas.

## Implementado nesta rodada
- Login público (PF/PJ) com captcha de imagem, bloqueio após 5 tentativas, senha forte e regeneração de sessão.
- Fluxo de cadastro PF/PJ com validação de CPF/CNPJ/telefone, unicidade de CPF/CNPJ/e-mail/telefone, suporte a múltiplos CNPJs vinculados ao mesmo CPF.
- Recuperação de senha com token (armazenado e logado em `storage/reset-links.log`), captcha e redefinição segura; contador de falhas é zerado após reset.
- Admin login agora tem captcha, bloqueio por tentativas e regen de sessão.
- Novas tabelas: `network_accounts`, `network_account_cnpjs`, `network_password_resets`, `network_audit_logs` + colunas de falha/bloqueio em `network_admins`.

## Implementado agora (segmentação e mensagens)
- Perfil obrigatório pós-login com dados de segmento, CNAE, porte, faturamento, colaboradores, região/UF/cidade, canais e objetivos, posição política; salva no banco e força grupos.
- Grupos automáticos: geral, política (esquerda/direita/neutro), região (UF), atividade e atividade-única (capacidade 1 com criação incremental), objetivos; membership criado no salvamento do perfil.
- Mensageria própria: rota `/network/messages`, envio 1:1 por e-mail cadastrado, isolamento político (esquerda x direita), rate-limit (10/min), auditoria de envios.
- Tabelas novas: `network_groups`, `network_group_members`, `network_messages`; colunas extras em `network_accounts` para perfil e política.
- Lista de grupos em `/network/groups` e API `/network/api/groups`.
- Admin APIs para listar contas, alterar posição política e destravar contas bloqueadas: `/network/api/admin/accounts`, `/network/api/admin/accounts/{id}/politics`, `/network/api/admin/accounts/{id}/unlock`.
- Roteamento centralizado com dispatcher (tabela de rotas + regex) em `index.php`, removendo cadeia de `if/else`.

## Ações rápidas sugeridas (próximos commits)
- Implementar regeneração de sessão + cookies seguros.
- Melhorar validações (CPF/CNPJ/telefone/limites).
- Paginador e filtros nas APIs de leads + índices.
- Criar log de auditoria e surface no painel.
- Trocar prompts do painel por modais simples/feedback visual.
