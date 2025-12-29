# Checkpoint – Módulo de E-mail (Marketing Digital)

Data: 2025-12-03
Responsável: Chatboot-IA Assist (GPT-5.1-Codex Preview)

## Onde estamos
- UI/Fluxos prontos para **Contas de envio** (lista + formulário completo) em `resources/views/marketing/email_accounts*.php`.
- Backend com validações e persistência (`app/Services/EmailAccountService.php`, `app/Repositories/EmailAccountRepository.php`, policies em `email_account_policies`).
- `MarketingController` expõe rotas de CRUD + importação de contatos e segmentos.
- Forms armazenam políticas (VS Code tooling, templates, compliance, warm-up, integrações API), mas ainda não há consumo por campanhas.

## O que falta/pendências
1. **Motor de disparo**: serviço/fila para usar as contas configuradas (seleção round-robin, limites hora/dia, logs, bounce handler).
2. **Health-check real**: job que autentica SMTP/API, testa SPF/DKIM e atualiza `last_health_check_at` + status DNS automaticamente.
3. **Uso das políticas**: ligar `email_account_policies` a campanhas (template engine, headers, warm-up, API provider).
4. **Métricas e entregabilidade**: dashboard de envios/bounces/spam e integrações com feedback loop.
5. **Testes automatizados**: smoke test de credenciais direto da UI antes de salvar.

## Próximos passos sugeridos
- Definir arquitetura do **orquestrador de campanhas** (queues + jobs) e responsabilidades (envio, tracking, bounce).
- Especificar endpoints/jobs para **health-check** e **monitoramento DNS**.
- Mapear dados mínimos para **relatórios** (entregues, bounces, opt-outs) e onde serão persistidos.
- Planejar integrações externas (Amazon SES, SendGrid, Mailtrap) para consumir `api_provider`.

> Este arquivo serve como referência rápida para retomarmos amanhã exatamente neste ponto.
