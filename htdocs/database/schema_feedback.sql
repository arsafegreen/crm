-- Feedbacks (qualificação)
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS feedbacks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    rater_user_id TEXT NOT NULL,
    target_user_id TEXT,
    target_cnpj TEXT,
    deal_id TEXT NOT NULL,
    score SMALLINT NOT NULL CHECK (score >= 0 AND score <= 10),
    body TEXT,
    visibility_score TEXT NOT NULL CHECK (visibility_score IN ('public','aggregate_only','private_admin')),
    visibility_body TEXT NOT NULL CHECK (visibility_body IN ('public','private_admin')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','under_review','removed')),
    allow_reply BOOLEAN NOT NULL DEFAULT TRUE,
    request_id TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (rater_user_id, target_user_id, target_cnpj, deal_id)
);

CREATE INDEX IF NOT EXISTS idx_feedbacks_target_user ON feedbacks (target_user_id);
CREATE INDEX IF NOT EXISTS idx_feedbacks_target_cnpj ON feedbacks (target_cnpj);
CREATE INDEX IF NOT EXISTS idx_feedbacks_status ON feedbacks (status);
CREATE INDEX IF NOT EXISTS idx_feedbacks_request_id ON feedbacks (request_id);

-- Aggregated view (scores public + aggregate_only, status active)
CREATE MATERIALIZED VIEW IF NOT EXISTS feedbacks_aggregate AS
SELECT 
    COALESCE(target_user_id, '') AS target_user_id,
    COALESCE(target_cnpj, '') AS target_cnpj,
    COUNT(*) FILTER (WHERE visibility_score IN ('public','aggregate_only') AND status='active') AS count_public,
    AVG(score)::numeric(4,2) FILTER (WHERE visibility_score IN ('public','aggregate_only') AND status='active') AS avg_public,
    COUNT(*) FILTER (WHERE status='active') AS count_all,
    AVG(score)::numeric(4,2) FILTER (WHERE status='active') AS avg_all
FROM feedbacks
GROUP BY COALESCE(target_user_id, ''), COALESCE(target_cnpj, '');

CREATE UNIQUE INDEX IF NOT EXISTS idx_feedbacks_aggregate_target ON feedbacks_aggregate (target_user_id, target_cnpj);

-- Denúncias sobre feedbacks/perfis
CREATE TABLE IF NOT EXISTS feedback_reports (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    feedback_id UUID,
    reporter_user_id TEXT NOT NULL,
    target_user_id TEXT,
    target_cnpj TEXT,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','under_review','closed','rejected')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_feedback_reports_feedback ON feedback_reports (feedback_id);
CREATE INDEX IF NOT EXISTS idx_feedback_reports_target_user ON feedback_reports (target_user_id);
CREATE INDEX IF NOT EXISTS idx_feedback_reports_target_cnpj ON feedback_reports (target_cnpj);
