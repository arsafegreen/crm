<?php
// Configuração de banco do Network (isolado do CRM). Por padrão, Postgres.
// Ajuste host/porta/credenciais conforme seu ambiente ou container local.
return [
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => 5432,
    'database' => 'network',
    'username' => 'network',
    'password' => 'network',
    'charset' => 'utf8',
    'collation' => 'utf8',
];
