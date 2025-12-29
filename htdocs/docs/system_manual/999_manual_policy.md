# 999 – Política de Atualização do Manual

1. **Escopo**: qualquer alteração em arquivos dentro de `app/`, `resources/`, `config/`, `scripts/`, `public/` ou `docs/system_manual/` exige revisão deste manual.
2. **Checklist de Pull Request**:
   - [ ] Identifique módulos tocados (ex.: `app/Controllers/FinanceController.php`).
   - [ ] Atualize o capítulo correspondente nesta pasta, descrevendo o que mudou.
   - [ ] Regere o PDF consolidado (ver seção *Publicação*).
   - [ ] Anexe o PDF à release interna (Configurações > Manual Técnico).
3. **Publicação**:
   - Rodar `php scripts/manual/build_manual.php` (script a ser criado) para concatenar os `*.md` em um único `manual.md`.
   - Converter para PDF com `pandoc` ou `wkhtmltopdf` e salvar em `storage/manual/manual.pdf`.
   - Atualizar flag `settings.manual_version` com o hash atual (`git rev-parse HEAD`).
4. **Alerta no Painel**: se `manual_version` diferir da hash atual, exibir aviso em Configurações solicitando atualização.
5. **Auditoria**: registrar no changelog interno (`docs/system_manual/changelog.md`) o histórico de versões do manual.
