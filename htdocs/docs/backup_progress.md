# Backup e Restauração - Status Rápido (18/12/2025)

## O que já foi feito
- Implementado serviço completo de snapshots (full/incremental) com manifestos em `storage/backups/manifests/`.
- UI do Gerenciador de Backups (`/backup-manager`) com criação de full/incremental, restauração em cadeia, download e checklist pós-restore.
- CLI `scripts/backup_now.php` com `--full`, `--incremental=<id>`, `--with-media`, `--restore=<id> --dest --force`, `--prune --keep-full --max-gb`, `--list`.
- Rotas e permissões: Kernel mapeou rotas do backup manager; AuthGuard exige `config.manage`; atalho “Backups” no layout para quem tem permissão.
- Retenção/limpeza: método `prune()` mantém N últimos full e suas cadeias; opção de limite de espaço.
- Ajustes de exclusão/leitura: ignoramos arquivos travados do Chromium (storage/whatsapp-web/*/session/Default/Network/*), pulamos arquivos não legíveis, evitamos chaves numéricas em manifest (arquivo “0” excluído).
- Full mais recente criado: `full_20251218_131002.zip` em `storage/backups/` (com mídias); manifest correspondente gravado.

## O que falta / próximos passos
- Verificar warning do ZipArchive::close (verificar se zip abriu/fechou ok; checar integridade do `full_20251218_131002.zip`).
- Testar restauração smoke: `php scripts/backup_now.php --restore=<id> --dest=storage/backups/restores/smoke --force` e rodar checklist (composer install, npm install gateway, permissões storage, .env/certs).
- Gerar incremental após próximas alterações: `php scripts/backup_now.php --incremental=<id_full> --note="..."`.
- Executar retenção: `php scripts/backup_now.php --prune --keep-full=2 --max-gb=50` ou pelo painel.
- (Opcional) Agendar: full semanal + incremental diário + prune diário (Task Scheduler/cron).
- (Opcional) Log/alertas: registrar logs das execuções e alertar falhas/limpeza.
