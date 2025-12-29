-- Additional indexes focused on non-encoded columns used by the CRM.
-- Run on every MySQL replica/primary without altering encrypted fields.

CREATE INDEX IF NOT EXISTS idx_clients_status_followup
    ON clients (status, next_follow_up_at);

CREATE INDEX IF NOT EXISTS idx_clients_stage_status
    ON clients (pipeline_stage_id, status);

CREATE INDEX IF NOT EXISTS idx_clients_created_at
    ON clients (created_at);

CREATE INDEX IF NOT EXISTS idx_client_protocols_client_created
    ON client_protocols (client_id, created_at);

CREATE INDEX IF NOT EXISTS idx_interactions_client_type_date
    ON interactions (client_id, interaction_type, occurred_at);

CREATE INDEX IF NOT EXISTS idx_tasks_client_due
    ON tasks (client_id, due_at, completed_at);

CREATE INDEX IF NOT EXISTS idx_certificates_client_expiration
    ON certificates (client_id, expires_at);

-- For lookup tables that store hashed identifiers next to ciphertext.
CREATE INDEX IF NOT EXISTS idx_clients_document_hash
    ON clients (document_hash);

-- Rebuild statistics after deploying the indexes.
ANALYZE TABLE
    clients,
    client_protocols,
    interactions,
    tasks,
    certificates;
