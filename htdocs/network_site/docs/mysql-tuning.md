# MySQL 8 – sugestão de configuração parruda (InnoDB)

Use como base e ajuste conforme o host. Pensado para SSD/NVMe, buffer pool alto e carga web.

```
[mysqld]
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci
skip-character-set-client-handshake

innodb_buffer_pool_size = 8G        # 50–70% da RAM do host dedicada ao MySQL
innodb_buffer_pool_instances = 8
innodb_log_file_size = 2G
innodb_log_buffer_size = 64M
innodb_flush_log_at_trx_commit = 1  # 2 se aceitar menor durabilidade
innodb_flush_method = O_DIRECT
innodb_file_per_table = 1
innodb_flush_neighbors = 0

max_connections = 300               # alinhar com PHP/Apache para evitar overload
thread_cache_size = 100
table_open_cache = 4000
tmp_table_size = 256M
max_heap_table_size = 256M
query_cache_type = 0
query_cache_size = 0

log_error = /var/log/mysql/error.log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.5

[client]
default-character-set = utf8mb4
```

Notas:
- Ajuste `innodb_buffer_pool_size` proporcional à RAM (ex.: 4G em hosts menores).
- `max_connections` deve casar com limites de PHP/Apache; prefira aumentar `innodb_buffer_pool_size` antes de subir conexões.
- Se usar RAID/SSD bons, manter `innodb_flush_log_at_trx_commit=1`; em ambientes menos críticos, `2` reduz fsync.
- Monitore slow log e crie índices adicionais conforme consultas reais (status+created_at, region/state, segment).
