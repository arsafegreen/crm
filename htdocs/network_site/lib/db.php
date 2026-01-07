<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config/database.php';
    $driver = strtolower((string)($config['driver'] ?? 'mysql'));

    if ($driver === 'pgsql') {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
            $config['host'],
            $config['port'] ?? 5432,
            $config['database']
        );
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], $options);
    return $pdo;
}

function ensureSchema(): void
{
    $pdo = db();
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'pgsql') {
        ensureSchemaPg($pdo);
        return;
    }
    ensureSchemaMysql($pdo);
}

function ensureSchemaMysql(PDO $pdo): void
{

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_at DATETIME NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
    $pdo->exec('ALTER TABLE network_admins ADD COLUMN IF NOT EXISTS failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE network_admins ADD COLUMN IF NOT EXISTS locked_at DATETIME NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS network_leads (
        id VARCHAR(36) PRIMARY KEY,
        request_id VARCHAR(36) NOT NULL,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(60) NOT NULL,
        company VARCHAR(160) NULL,
        primary_cnpj VARCHAR(32) NULL,
        cpf VARCHAR(32) NULL,
        birthdate DATE NULL,
        address VARCHAR(240) NULL,
        region VARCHAR(120) NOT NULL,
        area VARCHAR(120) NOT NULL,
        objective VARCHAR(240) NOT NULL,
        interest VARCHAR(240) NULL,
        message TEXT NULL,
        political_pref VARCHAR(24) NOT NULL DEFAULT 'neutral',
        political_access JSON NULL,
        entity_type VARCHAR(8) NOT NULL,
        consumer_mode TINYINT(1) NOT NULL DEFAULT 0,
        cv_link VARCHAR(240) NULL,
        skills TEXT NULL,
        ecommerce_interest TINYINT(1) NOT NULL DEFAULT 0,
        consent TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        suggested_groups JSON NULL,
        assigned_groups JSON NULL,
        cnpjs JSON NULL,
        areas JSON NULL,
        pending_cnpjs JSON NULL,
        user_agent VARCHAR(255) NULL,
        ip VARCHAR(64) NULL,
        decision_by VARCHAR(190) NULL,
        decision_note TEXT NULL,
        decision_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_email (email),
        INDEX idx_phone (phone),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_ads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        image_url VARCHAR(300) NULL,
        target_url VARCHAR(300) NOT NULL,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_active (is_active),
        INDEX idx_window (starts_at, ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type ENUM(\'pf\', \'pj\') NOT NULL,
        name VARCHAR(180) NOT NULL,
        cpf VARCHAR(32) NOT NULL UNIQUE,
        birthdate DATE NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        phone VARCHAR(60) NOT NULL UNIQUE,
        address VARCHAR(240) NULL,
        city VARCHAR(120) NULL,
        state VARCHAR(8) NULL,
        region VARCHAR(32) NULL,
        segment VARCHAR(160) NULL,
        cnae VARCHAR(32) NULL,
        company_size VARCHAR(32) NULL,
        revenue_range VARCHAR(32) NULL,
        employees INT NULL,
        sales_channels JSON NULL,
        objectives JSON NULL,
        political_pref VARCHAR(24) NOT NULL DEFAULT \'neutral\',
        political_access JSON NULL,
        primary_cnpj VARCHAR(32) NULL,
        cnpjs JSON NULL,
        password_hash VARCHAR(255) NOT NULL,
        failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_type (type),
        INDEX idx_created_accounts (created_at),
        INDEX idx_political (political_pref),
        INDEX idx_segment (segment),
        INDEX idx_region_state (region, state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS city VARCHAR(120) NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS state VARCHAR(8) NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS region VARCHAR(32) NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS segment VARCHAR(160) NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS cnae VARCHAR(32) NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS company_size VARCHAR(32) NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS revenue_range VARCHAR(32) NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS employees INT NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS sales_channels JSON NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS objectives JSON NULL');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS political_pref VARCHAR(24) NOT NULL DEFAULT \'neutral\'');
    $pdo->exec('ALTER TABLE network_accounts ADD COLUMN IF NOT EXISTS political_access JSON NULL');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_account_cnpjs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        cnpj VARCHAR(32) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL,
        CONSTRAINT fk_account_cnpjs FOREIGN KEY (account_id) REFERENCES network_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_type ENUM(\'admin\', \'user\') NOT NULL,
        account_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        ip VARCHAR(64) NULL,
        INDEX idx_token (token_hash),
        INDEX idx_account (account_type, account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_audit_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_type ENUM(\'admin\', \'user\', \'system\') NOT NULL,
        actor_id INT UNSIGNED NULL,
        action VARCHAR(120) NOT NULL,
        target VARCHAR(180) NULL,
        meta JSON NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_action (action),
        INDEX idx_created_logs (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(160) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        type VARCHAR(40) NOT NULL,
        capacity INT NULL,
        is_restricted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_type_groups (type),
        INDEX idx_created_groups (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_group_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id INT UNSIGNED NOT NULL,
        account_id INT UNSIGNED NOT NULL,
        role VARCHAR(16) NOT NULL DEFAULT \'member\',
        created_at DATETIME NOT NULL,
        CONSTRAINT fk_group_members_group FOREIGN KEY (group_id) REFERENCES network_groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_group_members_account FOREIGN KEY (account_id) REFERENCES network_accounts(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_group_account (group_id, account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_id INT UNSIGNED NOT NULL,
        recipient_id INT UNSIGNED NULL,
        group_id INT UNSIGNED NULL,
        body TEXT NOT NULL,
        meta JSON NULL,
        created_at DATETIME NOT NULL,
        CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES network_accounts(id) ON DELETE CASCADE,
        CONSTRAINT fk_msg_recipient FOREIGN KEY (recipient_id) REFERENCES network_accounts(id) ON DELETE SET NULL,
        CONSTRAINT fk_msg_group FOREIGN KEY (group_id) REFERENCES network_groups(id) ON DELETE CASCADE,
        INDEX idx_sender (sender_id, created_at),
        INDEX idx_recipient (recipient_id, created_at),
        INDEX idx_group (group_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
}

function ensureSchemaPg(PDO $pdo): void
{
    $sqlAdmins = <<<SQL
CREATE TABLE IF NOT EXISTS network_admins (
    id SERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    failed_attempts SMALLINT NOT NULL DEFAULT 0,
    locked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL
);
SQL;
    $pdo->exec($sqlAdmins);

    $sqlLeads = <<<SQL
CREATE TABLE IF NOT EXISTS network_leads (
    id VARCHAR(36) PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(60) NOT NULL,
    company VARCHAR(160) NULL,
    primary_cnpj VARCHAR(32) NULL,
    cpf VARCHAR(32) NULL,
    birthdate DATE NULL,
    address VARCHAR(240) NULL,
    region VARCHAR(120) NOT NULL,
    area VARCHAR(120) NOT NULL,
    objective VARCHAR(240) NOT NULL,
    interest VARCHAR(240) NULL,
    message TEXT NULL,
    political_pref VARCHAR(24) NOT NULL DEFAULT 'neutral',
    political_access JSONB NULL,
    entity_type VARCHAR(8) NOT NULL,
    consumer_mode SMALLINT NOT NULL DEFAULT 0,
    cv_link VARCHAR(240) NULL,
    skills TEXT NULL,
    ecommerce_interest SMALLINT NOT NULL DEFAULT 0,
    consent SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    suggested_groups JSONB NULL,
    assigned_groups JSONB NULL,
    cnpjs JSONB NULL,
    areas JSONB NULL,
    pending_cnpjs JSONB NULL,
    user_agent VARCHAR(255) NULL,
    ip VARCHAR(64) NULL,
    decision_by VARCHAR(190) NULL,
    decision_note TEXT NULL,
    decision_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
SQL;
    $pdo->exec($sqlLeads);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_leads_email ON network_leads (email);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_leads_phone ON network_leads (phone);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_leads_status ON network_leads (status);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_leads_created ON network_leads (created_at);');

    $sqlAds = <<<SQL
CREATE TABLE IF NOT EXISTS network_ads (
    id SERIAL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    image_url VARCHAR(300) NULL,
    target_url VARCHAR(300) NOT NULL,
    starts_at TIMESTAMP NULL,
    ends_at TIMESTAMP NULL,
    is_active SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
SQL;
    $pdo->exec($sqlAds);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ads_active ON network_ads (is_active);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ads_window ON network_ads (starts_at, ends_at);');

    $sqlAccounts = <<<SQL
CREATE TABLE IF NOT EXISTS network_accounts (
    id SERIAL PRIMARY KEY,
    type VARCHAR(2) NOT NULL,
    name VARCHAR(180) NOT NULL,
    cpf VARCHAR(32) NOT NULL UNIQUE,
    birthdate DATE NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(60) NOT NULL UNIQUE,
    address VARCHAR(240) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(8) NULL,
    region VARCHAR(32) NULL,
    segment VARCHAR(160) NULL,
    cnae VARCHAR(32) NULL,
    company_size VARCHAR(32) NULL,
    revenue_range VARCHAR(32) NULL,
    employees INT NULL,
    sales_channels JSONB NULL,
    objectives JSONB NULL,
    political_pref VARCHAR(24) NOT NULL DEFAULT 'neutral',
    political_access JSONB NULL,
    primary_cnpj VARCHAR(32) NULL,
    cnpjs JSONB NULL,
    password_hash VARCHAR(255) NOT NULL,
    failed_attempts SMALLINT NOT NULL DEFAULT 0,
    locked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
SQL;
    $pdo->exec($sqlAccounts);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_type ON network_accounts (type);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_created ON network_accounts (created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_political ON network_accounts (political_pref);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_segment ON network_accounts (segment);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_region_state ON network_accounts (region, state);');

    $pdo->exec('CREATE TABLE IF NOT EXISTS network_account_cnpjs (
        id SERIAL PRIMARY KEY,
        account_id INT NOT NULL REFERENCES network_accounts(id) ON DELETE CASCADE,
        cnpj VARCHAR(32) NOT NULL UNIQUE,
        created_at TIMESTAMP NOT NULL
    );');

    $pdo->exec("CREATE TABLE IF NOT EXISTS network_password_resets (
        id SERIAL PRIMARY KEY,
        account_type VARCHAR(10) NOT NULL,
        account_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL,
        ip VARCHAR(64) NULL
    );");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reset_token ON network_password_resets (token_hash);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reset_account ON network_password_resets (account_type, account_id);');

    $pdo->exec("CREATE TABLE IF NOT EXISTS network_audit_logs (
        id SERIAL PRIMARY KEY,
        actor_type VARCHAR(10) NOT NULL,
        actor_id INT NULL,
        action VARCHAR(120) NOT NULL,
        target VARCHAR(180) NULL,
        meta JSONB NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL
    );");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_action ON network_audit_logs (action);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_created ON network_audit_logs (created_at);');

    $pdo->exec("CREATE TABLE IF NOT EXISTS network_groups (
        id SERIAL PRIMARY KEY,
        slug VARCHAR(160) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        type VARCHAR(40) NOT NULL,
        capacity INT NULL,
        is_restricted SMALLINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL,
        updated_at TIMESTAMP NOT NULL
    );");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_groups_type ON network_groups (type);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_groups_created ON network_groups (created_at);');

    $pdo->exec("CREATE TABLE IF NOT EXISTS network_group_members (
        id SERIAL PRIMARY KEY,
        group_id INT NOT NULL REFERENCES network_groups(id) ON DELETE CASCADE,
        account_id INT NOT NULL REFERENCES network_accounts(id) ON DELETE CASCADE,
        role VARCHAR(16) NOT NULL DEFAULT 'member',
        created_at TIMESTAMP NOT NULL,
        CONSTRAINT uniq_group_account UNIQUE (group_id, account_id)
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS network_messages (
        id BIGSERIAL PRIMARY KEY,
        sender_id INT NOT NULL REFERENCES network_accounts(id) ON DELETE CASCADE,
        recipient_id INT NULL REFERENCES network_accounts(id) ON DELETE SET NULL,
        group_id INT NULL REFERENCES network_groups(id) ON DELETE CASCADE,
        body TEXT NOT NULL,
        meta JSONB NULL,
        created_at TIMESTAMP NOT NULL
    );");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_sender ON network_messages (sender_id, created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_recipient ON network_messages (recipient_id, created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_group ON network_messages (group_id, created_at);');
}

