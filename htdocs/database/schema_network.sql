-- Schema for Network leads on PostgreSQL
CREATE TABLE IF NOT EXISTS network_leads (
    id TEXT PRIMARY KEY,
    request_id TEXT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT NOT NULL,
    company TEXT,
    primary_cnpj TEXT,
    cpf TEXT,
    birthdate DATE,
    address TEXT,
    region TEXT,
    area TEXT,
    objective TEXT,
    interest TEXT,
    message TEXT,
    political_pref TEXT NOT NULL DEFAULT 'neutral',
    political_access JSONB NOT NULL DEFAULT '[]',
    entity_type TEXT NOT NULL DEFAULT 'pf',
    consumer_mode BOOLEAN NOT NULL DEFAULT FALSE,
    cv_link TEXT,
    skills TEXT,
    ecommerce_interest BOOLEAN NOT NULL DEFAULT FALSE,
    consent BOOLEAN NOT NULL DEFAULT FALSE,
    status TEXT NOT NULL DEFAULT 'pending',
    suggested_groups JSONB NOT NULL DEFAULT '[]',
    assigned_groups JSONB NOT NULL DEFAULT '[]',
    cnpjs JSONB NOT NULL DEFAULT '[]',
    areas JSONB NOT NULL DEFAULT '[]',
    pending_cnpjs JSONB NOT NULL DEFAULT '[]',
    user_agent TEXT,
    ip TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_network_leads_email ON network_leads (lower(email));
CREATE INDEX IF NOT EXISTS idx_network_leads_phone ON network_leads (phone);
CREATE INDEX IF NOT EXISTS idx_network_leads_cpf ON network_leads (cpf);
CREATE INDEX IF NOT EXISTS idx_network_leads_primary_cnpj ON network_leads (primary_cnpj);
CREATE INDEX IF NOT EXISTS idx_network_leads_status ON network_leads (status);
CREATE INDEX IF NOT EXISTS idx_network_leads_request_id ON network_leads (request_id);
CREATE INDEX IF NOT EXISTS idx_network_leads_cnpjs_gin ON network_leads USING gin (cnpjs);
CREATE INDEX IF NOT EXISTS idx_network_leads_areas_gin ON network_leads USING gin (areas);
CREATE INDEX IF NOT EXISTS idx_network_leads_suggested_groups_gin ON network_leads USING gin (suggested_groups);
CREATE INDEX IF NOT EXISTS idx_network_leads_political_access_gin ON network_leads USING gin (political_access);
