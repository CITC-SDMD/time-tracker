-- docs/DEVELOPMENT_PLAN.md §7. All times are UTC milliseconds (integers).

CREATE TABLE sessions (
  id               TEXT PRIMARY KEY,         -- UUID v7, made on the PC. Also the sessions.id primary key on the server.
  user_id          TEXT NOT NULL,            -- Laravel users.id of the logged-in employee (sent as a string)
  device_id        TEXT NOT NULL,            -- this PC (UUID saved in app_state on first run)
  session_type     TEXT NOT NULL CHECK (session_type IN ('APPLICATION','IDLE')),
  app_name         TEXT,                     -- friendly name, e.g. "Visual Studio Code" (APPLICATION)
  process_name     TEXT,                     -- e.g. "Code.exe" (APPLICATION)
  window_title     TEXT,                     -- last title seen; may be NULL per office setting
  idle_app_name    TEXT,                     -- app in front while idle, e.g. "Zoom" (IDLE)
  started_at       INTEGER NOT NULL,
  ended_at         INTEGER,                  -- NULL while the session is open
  last_seen_at     INTEGER NOT NULL,         -- updated every 30s; used after a crash
  duration_seconds INTEGER,                  -- from the monotonic clock, set on close
  clock_changed    INTEGER NOT NULL DEFAULT 0,
  sync_status      TEXT NOT NULL DEFAULT 'OPEN'
                   CHECK (sync_status IN ('OPEN','PENDING','SYNCED','REJECTED')),
  created_at       INTEGER NOT NULL
);
CREATE INDEX idx_sessions_user_started ON sessions (user_id, started_at);
CREATE INDEX idx_sessions_open ON sessions (ended_at) WHERE ended_at IS NULL;

CREATE TABLE sync_queue (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type     TEXT NOT NULL,             -- 'SESSION' (only type for now)
  entity_id       TEXT NOT NULL UNIQUE,      -- sessions.id; UNIQUE stops double queueing
  user_id         TEXT NOT NULL,             -- only sent with this user's token
  payload         TEXT NOT NULL,             -- the exact JSON to send (Phase 4 builds the sender)
  attempts        INTEGER NOT NULL DEFAULT 0,
  last_attempt_at INTEGER,
  next_attempt_at INTEGER NOT NULL,          -- for retry wait times
  status          TEXT NOT NULL DEFAULT 'PENDING'
                  CHECK (status IN ('PENDING','DONE','FAILED')),
  last_error      TEXT,
  created_at      INTEGER NOT NULL
);
CREATE INDEX idx_queue_ready ON sync_queue (user_id, status, next_attempt_at);

CREATE TABLE app_state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
-- keys: schema_version, device_id, current_user_id, was_tracking,
--       last_sync_at, last_sync_error, office_settings_json
