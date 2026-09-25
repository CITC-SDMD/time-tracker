-- the server now uses lowercase enum values (roles, active/inactive, tracking states, session types,
-- window title mode), and so does the app. this converts what is already stored on this pc.

-- session_type has a check constraint, and sqlite cannot alter one: rebuild the table.
CREATE TABLE sessions_new (
  id               TEXT PRIMARY KEY,
  user_id          TEXT NOT NULL,
  device_id        TEXT NOT NULL,
  session_type     TEXT NOT NULL CHECK (session_type IN ('application','idle')),
  app_name         TEXT,
  process_name     TEXT,
  window_title     TEXT,
  idle_app_name    TEXT,
  started_at       INTEGER NOT NULL,
  ended_at         INTEGER,
  last_seen_at     INTEGER NOT NULL,
  duration_seconds INTEGER,
  clock_changed    INTEGER NOT NULL DEFAULT 0,
  sync_status      TEXT NOT NULL DEFAULT 'OPEN'
                   CHECK (sync_status IN ('OPEN','PENDING','SYNCED','REJECTED')),
  created_at       INTEGER NOT NULL
);
INSERT INTO sessions_new
  SELECT id, user_id, device_id, lower(session_type), app_name, process_name, window_title,
         idle_app_name, started_at, ended_at, last_seen_at, duration_seconds, clock_changed,
         sync_status, created_at
  FROM sessions;
DROP TABLE sessions;
ALTER TABLE sessions_new RENAME TO sessions;
CREATE INDEX idx_sessions_user_started ON sessions (user_id, started_at);
CREATE INDEX idx_sessions_open ON sessions (ended_at) WHERE ended_at IS NULL;

-- sessions already waiting to be sent carry their type inside the json that will be posted.
UPDATE sync_queue
   SET payload = json_set(payload, '$.type', lower(json_extract(payload, '$.type')))
 WHERE json_valid(payload) AND json_extract(payload, '$.type') IS NOT NULL;

-- cached copies of the server's answers (profile and office settings).
UPDATE app_state
   SET value = json_set(
         value,
         '$.role', lower(json_extract(value, '$.role')),
         '$.status', CASE json_extract(value, '$.status')
                       WHEN 'ACTIVE' THEN 'active'
                       WHEN 'DEACTIVATED' THEN 'inactive'
                       ELSE json_extract(value, '$.status')
                     END,
         '$.officeSettings.windowTitleMode', lower(json_extract(value, '$.officeSettings.windowTitleMode')))
 WHERE key = 'me_json' AND json_valid(value) AND json_extract(value, '$.role') IS NOT NULL;

UPDATE app_state
   SET value = json_set(value, '$.windowTitleMode', lower(json_extract(value, '$.windowTitleMode')))
 WHERE key = 'office_settings_json' AND json_valid(value) AND json_extract(value, '$.windowTitleMode') IS NOT NULL;
