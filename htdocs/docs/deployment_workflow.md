# Fluxo Seguro de Publicação (Dev → Produção)

> Versão 0.1 · Atualizado em 27/11/2025

## 1. Objetivo
Garantir que melhorias desenvolvidas em ambiente de desenvolvimento sejam aplicadas na produção sem impactar os dados existentes e mantendo rastreabilidade.

## 2. Princípios
- **Código versionado, dados isolados**: apenas arquivos de aplicação são sincronizados entre ambientes. Cada ambiente mantém seu próprio banco e `storage/`.
- **Migrações como única fonte de verdade**: qualquer mudança de schema deve existir em `app/Database/Migrations` e ser executada via `php scripts/migrate.php`.
- **Backups antes de alterações críticas**: produção só recebe novas versões após backup verificado.

## 3. Estrutura de ambientes
| Ambiente | Banco | URL | Observações |
| --- | --- | --- | --- |
| Desenvolvimento | `storage/database.sqlite` (ou cópia anonimizada) | `http://localhost:8080` | Pode conter dados fictícios; nunca sincronize com o banco de produção |
| Produção | Configurado via `.env` dedicado | `https://suite.exemplo.com` | Dados reais; `storage/` exclusivo |

### 3.1 Variáveis `.env`
- Dev: `.env` com `APP_ENV=local`, `APP_DEBUG=true`, banco local.
- Prod: `.env` com `APP_ENV=production`, `APP_DEBUG=false`, `APP_FORCE_HTTPS=true`, caminhos para banco e chave criptografada.
- Nunca versione `.env`; armazene fora do Git.

## 4. Fluxo em Git
1. **Branch principal (`main`)** sempre reflete o código rodando em produção.
2. **Nova melhoria**: `git checkout -b feature/nome-da-melhoria` a partir de `main`.
3. Desenvolva e teste em dev; registre commits pequenos e claros.
4. Abra PR/merge para `main`. Execute `composer test` (quando existir) + validações manuais.
5. Após aprovação, crie tag (`vYYYY.MM.DD-n`) ou anote o hash para deploy.

## 5. Pacotes de atualização
Para simplificar a publicação em máquinas fora do ambiente de desenvolvimento, utilize os novos scripts de empacotamento e aplicação.

### 5.1 Geração do pacote (dev)
1. Certifique-se de que o código está na revisão desejada e que `composer install` já foi executado.
2. Rode:
   ```bash
   php scripts/package_release.php vYYYY.MM.DD-1
   ```
   - O arquivo será salvo em `storage/releases/<versao>.zip`.
   - Use `--skip-vendor` para não incluir a pasta `vendor/` (nesse caso, será obrigatório rodar `composer install --no-dev` ao aplicar a release).
   - O zip inclui `release_manifest.json` com hash do commit e lista de arquivos.

### 5.2 Aplicação do pacote (prod)
1. Copie o `.zip` gerado para o servidor de produção (qualquer diretório acessível).
2. Execute:
   ```bash
   php scripts/apply_update.php caminho/para/release.zip
   ```
3. O script irá:
   - Criar backup automático do código atual em `storage/backups/code_*.zip`.
   - Extrair o pacote para um diretório temporário.
   - Copiar apenas os arquivos listados no manifest (preserva `storage/`, `.env`, uploads, banco etc.).
   - Executar `php scripts/migrate.php` usando o PHP do próprio servidor.
4. Ao final, revise o log gerado no terminal. Em caso de falha, restaure o backup criado antes da cópia.
5. Alternativa: pela interface em **Config > Atualização do sistema** (somente admins) é possível enviar o `.zip`; o painel usa os mesmos scripts e exibe os logs no navegador.

## 6. Deploy para produção
1. **Backup obrigatório**
   - Banco de dados (`storage/database.sqlite` ou arquivo `.enc`).
   - Diretórios `storage/uploads`, `storage/backups` e arquivos `.env`, `storage/database.key`.
2. **Preparar build**
   ```bash
   git checkout main
   git pull origin main
   composer install --no-dev --optimize-autoloader
   npm run build # se/ quando houver assets
   ```
3. **Sincronizar arquivos**
   - Copie apenas código-fonte (ex.: via `rsync` ou deploy script) excluindo `storage/`, `.env`, `vendor/` (caso instale direto em prod) e qualquer arquivo específico do ambiente.
   - Exemplo `rsync` (Linux):
     ```bash
     rsync -avz --delete \
       --exclude 'storage/' --exclude '.env' --exclude '.git' \
       ./ usuario@prod:/var/www/marketing-suite
     ```
4. **Executar migrações** (já em produção):
   ```bash
   php scripts/migrate.php
   ```
5. **Limpeza/checagem**
   - Verificar permissões de `storage/`.
   - Testar login, CRM, importação rápida e qualquer módulo afetado.

## 7. Banco de dados & dados operacionais
- Produção nunca deve receber o arquivo `storage/database.sqlite` do dev. Caso precise atualizar schema, use migrations.
- Para testar com dados reais, gere export anonymizado ou backup pontual da produção, restaure em dev e **imediatamente** desconecte o dev da rede externa.
- Scripts em `scripts/` (reset de senha, recalcular status) devem ser executados no ambiente correspondente apontando para o banco correto.

## 8. Checklists
### 7.1 Pré-commit
- `composer dump-autoload` (garantir autoload atualizado).
- Rodar testes/unitários manuais essenciais.
- Conferir que novos arquivos foram adicionados ao Git (exceto os ignorados).

### 7.2 Pré-deploy
- Backup concluído e verificado.
- Migrações revisadas (ordem correta, idempotentes).
- `.env` de produção atualizado com novas variáveis (se houver).
- Tarefas cron/automação compatíveis com mudanças.

### 7.3 Pós-deploy
- Monitorar log `storage/logs/*.log` por 30 minutos.
- Confirmar execução de automações e filas (se ativas).
- Registrar release em changelog interno (hash/tag + resumo).

## 9. Automação opcional
- **CI/CD simples**: GitHub Actions / GitLab CI rodando `composer validate`, testes e lint a cada push.
- **Deploy assistido**: script PowerShell/Bash que executa etapas 2–5 automaticamente.
- **Snapshots do banco**: agendar backup (CRON) diário para armazenamento externo seguro.

## 10. Recuperação
1. Restaurar backup do código (ou dar `git checkout <tag>`).
2. Restaurar banco e `storage/` a partir do backup correspondente.
3. Validar funcionalidades críticas.
4. Comunicar stakeholders e registrar incidente.

---
Com este fluxo, qualquer melhoria desenvolvida no ambiente de dev pode ser migrada para produção sem alterar os dados existentes, mantendo histórico e rollback simples.
