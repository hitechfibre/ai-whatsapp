-- HitechFibre Migration 002
-- Adds: contact_id, customer_name, status, department, after_hours, bot_paused, escalated, ticket_id
-- Adds: splynx_customer_id to conversations, content column alias for messages
-- Safe to re-run (uses ADD COLUMN IF NOT EXISTS / ALTER TABLE IGNORE)

-- ─────────────────────────────────────────────────────────────────────
-- conversations — add new columns (MySQL syntax — use SQLite ALTER below)
-- ─────────────────────────────────────────────────────────────────────

-- MySQL 8+
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS contact_id          VARCHAR(80)  DEFAULT NULL;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS customer_name       VARCHAR(120) DEFAULT NULL;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS status              VARCHAR(20)  NOT NULL DEFAULT 'active';
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS department          VARCHAR(30)  DEFAULT NULL;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS after_hours         TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS bot_paused          TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS escalated           TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS ticket_id           VARCHAR(40)  DEFAULT NULL;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS splynx_customer_id  INT          DEFAULT NULL;

-- ─────────────────────────────────────────────────────────────────────
-- messages — add 'content' as alias for 'text' (admin API uses 'content')
-- ─────────────────────────────────────────────────────────────────────
ALTER TABLE messages ADD COLUMN IF NOT EXISTS content TEXT DEFAULT NULL;

-- Populate content from text for existing rows
UPDATE messages SET content = text WHERE content IS NULL AND text IS NOT NULL;

-- ─────────────────────────────────────────────────────────────────────
-- Indexes
-- ─────────────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_conv_status     ON conversations (status);
CREATE INDEX IF NOT EXISTS idx_conv_dept       ON conversations (department);
CREATE INDEX IF NOT EXISTS idx_conv_contact    ON conversations (contact_id);
CREATE INDEX IF NOT EXISTS idx_conv_created    ON conversations (created_at);
