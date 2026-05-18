-- HitechFibre AI WhatsApp Support — Database Schema
-- Supports both MySQL 8+ and SQLite 3.35+
-- Run: php artisan migrate  OR  sqlite3 state/hitechfibre.db < migrations/001_schema.sql

-- ─────────────────────────────────────────────────────────────────────
--  Conversations — one row per WhatsApp conversation
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversations (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,  -- MySQL: BIGINT AUTO_INCREMENT
    phone            VARCHAR(20)   NOT NULL,
    contact_name     VARCHAR(120)  DEFAULT NULL,
    conversation_id  VARCHAR(80)   DEFAULT NULL,   -- respond.io conversation ID
    splynx_id        VARCHAR(40)   DEFAULT NULL,   -- Splynx customer ID
    splynx_ticket_id VARCHAR(40)   DEFAULT NULL,   -- ticket created at close
    state            VARCHAR(30)   NOT NULL DEFAULT 'NEW',
    context          TEXT          NOT NULL DEFAULT '{}',    -- JSON blob of context flags
    messages         TEXT          NOT NULL DEFAULT '[]',    -- full transcript JSON array
    state_history    TEXT          NOT NULL DEFAULT '[]',    -- transition log JSON
    intent           VARCHAR(30)   DEFAULT NULL,
    created_at       DATETIME      NOT NULL,
    updated_at       DATETIME      NOT NULL,
    last_message_at  DATETIME      NOT NULL,
    closed_at        DATETIME      DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_conv_phone       ON conversations (phone);
CREATE INDEX IF NOT EXISTS idx_conv_state       ON conversations (state);
CREATE INDEX IF NOT EXISTS idx_conv_updated     ON conversations (updated_at);
CREATE INDEX IF NOT EXISTS idx_conv_conv_id     ON conversations (conversation_id);

-- ─────────────────────────────────────────────────────────────────────
--  Messages — denormalized for fast dashboard queries (optional)
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    phone           VARCHAR(20) NOT NULL,
    role            VARCHAR(10) NOT NULL,   -- 'customer' | 'bot' | 'agent'
    text            TEXT        NOT NULL,
    intent          VARCHAR(30) DEFAULT NULL,
    created_at      DATETIME    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages (conversation_id);
CREATE INDEX IF NOT EXISTS idx_msg_phone ON messages (phone);

-- ─────────────────────────────────────────────────────────────────────
--  Customers — local cache of Splynx records
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    splynx_id   VARCHAR(40) UNIQUE NOT NULL,
    name        VARCHAR(120) DEFAULT NULL,
    phone       VARCHAR(20)  DEFAULT NULL,
    mobile      VARCHAR(20)  DEFAULT NULL,
    email       VARCHAR(120) DEFAULT NULL,
    address     TEXT         DEFAULT NULL,
    status      VARCHAR(30)  DEFAULT NULL,
    overdue     BOOLEAN      NOT NULL DEFAULT 0,
    raw_json    TEXT         NOT NULL DEFAULT '{}',
    synced_at   DATETIME     NOT NULL,
    created_at  DATETIME     NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cust_phone  ON customers (phone);
CREATE INDEX IF NOT EXISTS idx_cust_mobile ON customers (mobile);

-- ─────────────────────────────────────────────────────────────────────
--  Tickets — track tickets created in Splynx
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tickets (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id  INTEGER NOT NULL REFERENCES conversations(id),
    splynx_ticket_id VARCHAR(40) DEFAULT NULL,
    phone            VARCHAR(20) NOT NULL,
    subject          TEXT NOT NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'created',
    created_at       DATETIME NOT NULL
);

-- ─────────────────────────────────────────────────────────────────────
--  Event log — dedup + audit trail
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id   VARCHAR(80) UNIQUE NOT NULL,
    phone      VARCHAR(20) DEFAULT NULL,
    event_type VARCHAR(40) DEFAULT NULL,
    processed  BOOLEAN NOT NULL DEFAULT 0,
    payload    TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_event_id ON webhook_events (event_id);
