# CRM Query Optimization Plan

This note documents actionable steps to speed up CRM workloads that span multiple databases with security-encoded payloads. The goal is to minimize the amount of decoding work performed at runtime without altering the encrypted schema or dropping existing protections.

## 1. Current Constraints
- Multiple SQLite/MySQL databases contain the CRM data; sensitive columns such as `document`, `email`, or `notes` are stored encoded.
- Reporting and automation endpoints frequently run cross-database joins and repeatedly decode the same values, increasing CPU usage.
- Because the ciphertext varies per row, traditional indexes on the encoded columns are ineffective.

## 2. High-Impact Strategies
1. **Cache decoded projections**
   - After decoding sensitive fields for a request, push the normalized payload to a fast cache (Redis or APCu) using a key such as `crm:client:{id}:snapshot`.
   - Add cache invalidation hooks in repositories whenever `clients`, `client_protocols`, or `client_contacts` tables change.
   - Expose helper functions `cache_get_decoded($table, $id)` and `cache_store_decoded(...)` to avoid duplicating logic inside controllers.

2. **Leverage non-encoded columns for filtering**
   - Many workflows filter by `id`, `status`, `pipeline_stage_id`, `created_at`, or `next_follow_up_at`. Ensure the following composite indexes exist on the MySQL replicas:
     - `clients(status, next_follow_up_at)`
     - `clients(pipeline_stage_id, status)`
     - `client_protocols(client_id, created_at)`
     - `interactions(client_id, interaction_type, occurred_at)`
   - Keep predicates anchored on these columns and only decode the secure fields in the `SELECT` list (never inside `WHERE`).

3. **Query refactors**
   - Replace constructs like `WHERE decode(document) = :cpf` with `WHERE document_hash = :hash` by storing a deterministic hash (still encrypted if needed) alongside the ciphertext.
   - When hashes are unavailable, push the decode function to an outer query: `SELECT ... FROM (SELECT id, decode(document) AS doc FROM clients) c WHERE c.doc = :cpf`. This ensures the decoder runs once per row instead of multiple times.

4. **Configuration tuning**
   - Enable MySQL query cache equivalents (InnoDB buffer pool, prepared statement caching) and tweak PHP OPcache/JIT so repeated controller invocations stay in memory.
   - Run slow-query logging across all CRM DBs to capture statements whose decoding step dominates runtime.

## 3. Next Steps
- Apply the SQL in `database/sql/crm_indexes.sql` on each MySQL node.
- Deploy the Redis-backed cache script in `scripts/cache_crm_clients.php` and wire it into `ClientRepository`.
- Merge the recommended settings from `config/performance/mysql.cnf` and `config/performance/php_opcache.ini` into the respective environments.
- Monitor latency via existing application metrics; target <150 ms p95 for CRM autocomplete calls.
