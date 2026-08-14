-- NSRC Flex-Cadre database schema (SQLite)

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    call_sign     TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    email         TEXT NOT NULL,
    first_name    TEXT NOT NULL DEFAULT '',
    last_name     TEXT NOT NULL DEFAULT '',
    street_address TEXT NOT NULL DEFAULT '',
    city          TEXT NOT NULL DEFAULT '',
    state         TEXT NOT NULL DEFAULT '',
    zip_code      TEXT NOT NULL DEFAULT '',
    phone_number  TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL,   -- system date/time, not shown in the UI
    updated_at    TEXT NOT NULL,   -- system date/time, not shown in the UI
    last_login    TEXT,            -- system date/time of most recent successful login
    is_admin      TEXT NOT NULL DEFAULT ''  -- 'Y' = delegated admin; blank/N = not. WD9GYM is always super-admin regardless of this (see config.php).
);

-- North Shore Radio Club membership roster, imported from a CSV export
-- of the club's Google Sheet (see members_import.php). Used to validate
-- that a Call Sign creating an account is a current, dues-paid member.
CREATE TABLE IF NOT EXISTS members (
    call_sign      TEXT PRIMARY KEY,
    first_name     TEXT,
    last_name      TEXT,
    dues_column    TEXT,   -- which spreadsheet column header was checked (e.g. "2026")
    dues_value     TEXT,   -- the raw cell value found there
    is_current     INTEGER NOT NULL DEFAULT 0,
    overridden     INTEGER NOT NULL DEFAULT 0,  -- 1 = admin manually set is_current; re-imports won't touch it
    comments       TEXT,
    imported_at    TEXT NOT NULL
);

-- The five Flex radios the group schedules. Seeded here so the
-- upcoming scheduling feature has something to attach to.
CREATE TABLE IF NOT EXISTS radios (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT NOT NULL UNIQUE
);

INSERT OR IGNORE INTO radios (name) VALUES ('Skokie');
INSERT OR IGNORE INTO radios (name) VALUES ('Northfield');
INSERT OR IGNORE INTO radios (name) VALUES ('Northbrook');
INSERT OR IGNORE INTO radios (name) VALUES ('MunAV640');
INSERT OR IGNORE INTO radios (name) VALUES ('MunEndfed');
-- Tracks the on-demand activation state of the two remotely-powered
-- radios (MunEndfed and MunAV640). Bridges the gap between a button
-- click and later page loads, since MQTT itself has no memory here -
-- only the two remote-controlled radios get a row; the other three
-- don't need remote activation at all.
CREATE TABLE IF NOT EXISTS radio_status (
    radio_name  TEXT PRIMARY KEY,
    status      TEXT NOT NULL,
    activated_at TEXT
);

INSERT OR IGNORE INTO radio_status (radio_name, status) VALUES ('MunEndfed', 'Available for Activation');
INSERT OR IGNORE INTO radio_status (radio_name, status) VALUES ('MunAV640', 'Available for Activation');
-- Reservations for the scheduling feature. call_sign_snapshot records the
-- Call Sign at the moment of booking, so a reservation still shows who
-- made it even if that account is later deleted (accounts can be
-- deleted; their reservations are deliberately NOT deleted with them).
CREATE TABLE IF NOT EXISTS reservations (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    radio_id           INTEGER NOT NULL REFERENCES radios(id),
    user_id            INTEGER REFERENCES users(id),
    call_sign_snapshot TEXT NOT NULL,
    start_time         TEXT NOT NULL,
    end_time           TEXT NOT NULL,
    created_at         TEXT NOT NULL
);
