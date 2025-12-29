# Manual Técnico – Visão Geral da Estrutura

Este manual documenta os módulos proprietários do `htdocs/` que demandam manutenção recorrente. Os diretórios de terceiros (Apache, phpMyAdmin, vendor, etc.) ficam fora do escopo para evitar divergências durante atualizações.

## Diretórios Prioritários

| Diretório | Descrição | Status de documentação |
| --- | --- | --- |
| `app/` | Código PHP principal (controllers, serviços, repositórios, segurança, suporte). | A documentar em capítulos dedicados por submódulo. |
| `bootstrap/` | Inicialização da aplicação, carga de env/config e registro do autoloader. | Será documentado resumidamente junto ao fluxo de request. |
| `config/` | Arquivos de configuração organizados por domínio (app, database, marketing, etc.). | Mapeamento por arquivo na seção de referência. |
| `public/` | Front controller (`index.php`), assets públicos e .htaccess. | Descrever fluxo HTTP e dependências. |
| `resources/` | Views (layouts, modais, componentes). | Documentar por área funcional (dashboard, marketing, finance, etc.). |
| `scripts/` | Ferramentas de CLI: migrações, marketing workers, importadores. | Documentar cada script e parâmetros. |
| `storage/` | Logs, uploads e cache. (Sem código, apenas referência operacional.) | Registrar finalidade e políticas de retenção. |
| `tests/` | Harness de testes (atualmente placeholders). | Atualizar conforme criarmos suites. |

## Organização da Documentação

Cada subdiretório receberá um capítulo numerado (`101_app_controllers.md`, `102_app_services.md`, etc.) com os seguintes tópicos:

1. **Objetivo do módulo**
2. **Arquivos-chave** e breve explicação do papel de cada classe/função
3. **Fluxo de dados** (entradas, dependências, outputs)
4. **Pontos de extensão/manutenção**
5. **Checklist pós-alteração** (o que precisa ser revisado ao modificar o módulo)

## Próximos Passos

1. Criar os capítulos para `app/` (controllers, services, repositories, support, security).
2. Documentar `resources/views` agrupando por área funcional.
3. Registrar scripts CLI e rotinas de worker.
4. Converter o índice completo para PDF (via `pandoc` ou biblioteca PHP) e disponibilizar o download em Configurações.
5. Implementar política interna: todo merge em `main` deve atualizar os capítulos impactados e regenerar o PDF.
