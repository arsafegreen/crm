# 401 – Configurações (`config/*.php`)

Configurações são arrays simples carregados pelo helper `config()` na primeira chamada. Cada arquivo representa um domínio.

| Arquivo | Conteúdo |
| --- | --- |
| `app.php` | Nome, env, debug, URL base, timezone e flag `force_https`. |
| `database.php` | Caminho do SQLite e opções PDO. |
| `marketing.php` | Categorias do centro de preferências (`pref_campaigns`, `pref_case_studies`, etc.). Deve ser atualizado sempre que novas categorias forem ofertadas. |
| `performance/*.php` | Tunables de cache e limites. |

### Variáveis de ambiente (`.env`)

- `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_FORCE_HTTPS` – comportamento global.
- `SMTP_*` – credenciais default para fallback de envio.
- `DB_PATH` – aponta para `storage/database.sqlite` por padrão.

> **Procedimento**: ao adicionar chave nova em `config/`, atualizar este capítulo e descrever o efeito da flag. Também adicionar ao `.env.example` quando fizer sentido.
