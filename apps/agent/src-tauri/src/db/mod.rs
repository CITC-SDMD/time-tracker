//! Local SQLite storage (docs/DEVELOPMENT_PLAN.md §7). Owns the connection and every
//! SQL statement — `tracker::engine` never touches `rusqlite` directly, it only calls
//! the typed methods here. All times are stored as UTC milliseconds (`i64`).

use std::path::Path;

use rusqlite::{params, Connection};
use serde::Serialize;

use crate::platform::input::InputStats;

pub mod crypt;
use crypt::Cipher;

/// `app_state` values that hold personal details and are stored encrypted (the rest are switches and addresses).
const SEALED_STATE_KEYS: &[&str] = &["me_json"];

/// (schema_version, migration SQL). Future ones are
/// appended here and applied in order, each preceded by a `tracker.db.bak` copy.
const MIGRATIONS: &[(i64, &str)] = &[
    (1, include_str!("migrations/001_init.sql")),
    (2, include_str!("migrations/002_lowercase_enums.sql")),
    (3, include_str!("migrations/003_screenshots.sql")),
    (4, include_str!("migrations/004_input_stats.sql")),
    (5, include_str!("migrations/005_tasks.sql")),
];

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SessionType {
    Application,
    Idle,
}

impl SessionType {
    fn as_str(self) -> &'static str {
        match self {
            Self::Application => "application",
            Self::Idle => "idle",
        }
    }

    fn from_str(s: &str) -> Self {
        match s {
            "idle" => Self::Idle,
            _ => Self::Application,
        }
    }
}

pub struct NewSession {
    pub id: String,
    pub user_id: String,
    pub device_id: String,
    pub session_type: SessionType,
    pub app_name: Option<String>,
    pub process_name: Option<String>,
    pub window_title: Option<String>,
    pub idle_app_name: Option<String>,
    pub started_at: i64,
    pub last_seen_at: i64,
    /// the task picked when this session started, if any (see `tracker::engine::set_current_task`)
    pub task_id: Option<String>,
}

#[derive(Debug, Clone)]
pub struct SessionRow {
    pub id: String,
    pub session_type: SessionType,
    pub app_name: Option<String>,
    pub idle_app_name: Option<String>,
    pub started_at: i64,
    pub ended_at: Option<i64>,
    pub duration_seconds: Option<i64>,
    pub sync_status: String,
    pub task_id: Option<String>,
}

/// The exact JSON a `sync_queue` row carries — matches packages/shared's
/// `AgentSyncRequest.sessions[]` shape (camelCase on the wire). Phase 4's sync worker
/// reads this back out of `sync_queue.payload` and POSTs it as-is; Phase 3 never sends it.
#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct SyncPayload {
    id: String,
    #[serde(rename = "type")]
    session_type: String,
    app_name: Option<String>,
    process_name: Option<String>,
    window_title: Option<String>,
    idle_app_name: Option<String>,
    started_at: i64,
    ended_at: i64,
    duration_seconds: i64,
    clock_changed: bool,
    /// The activity check counts of this session; left out when there are none.
    #[serde(skip_serializing_if = "Option::is_none")]
    input_stats: Option<InputStats>,
    /// The task picked while this was tracked, or None for general, untagged time.
    task_id: Option<String>,
}

/// A screenshot waiting to be sent (see `screenshot`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ScreenshotRow {
    pub id: String,
    pub user_id: String,
    pub taken_at: i64,
    pub path: String,
    pub width: i64,
    pub height: i64,
    pub attempts: i64,
}

pub struct Db {
    conn: Connection,
    cipher: Cipher,
}

impl Db {
    /// Opens (creating if absent) the database at `db_path`, verifies integrity, and
    /// runs any pending migrations. A failed integrity check quarantines the old file
    /// (renamed `tracker.db.corrupt-<unix_ms>`) and starts fresh — see docs §7.4. The same happens when the file
    /// holds encrypted values but the key is new (`key_is_new`): the key they belong to is gone, so they can never
    /// be read again, and a fresh file is better than an app that cannot start.
    pub fn open(db_path: &Path, cipher: Cipher, key_is_new: bool) -> rusqlite::Result<Self> {
        let conn = Connection::open(db_path)?;
        Self::configure(&conn)?;

        let quick_check: String = conn.query_row("PRAGMA quick_check", [], |row| row.get(0))?;
        let lost_key = key_is_new && cipher.enabled() && Self::holds_sealed_values(&conn);
        if quick_check != "ok" || lost_key {
            drop(conn);
            let corrupt_path = db_path.with_file_name(format!(
                "tracker.db.corrupt-{}",
                chrono::Utc::now().timestamp_millis()
            ));
            let _ = std::fs::rename(db_path, &corrupt_path);
            tracing::error!(
                corrupt_path = %corrupt_path.display(),
                lost_key,
                "tracker.db is unusable (failed PRAGMA quick_check, or its encryption key is gone); quarantined and recreated"
            );

            let conn = Connection::open(db_path)?;
            Self::configure(&conn)?;
            let db = Self { conn, cipher };
            db.migrate(Some(db_path))?;
            db.encrypt_existing(Some(db_path))?;
            // Tell the server on the next sync that this PC's history was lost (`dbReset`).
            db.set_app_state("db_reset_pending", "1")?;
            return Ok(db);
        }

        let db = Self { conn, cipher };
        db.migrate(Some(db_path))?;
        db.encrypt_existing(Some(db_path))?;
        Ok(db)
    }

    /// The key holder, for the waiting screenshot files.
    pub fn cipher(&self) -> &Cipher {
        &self.cipher
    }

    /// Whether any sealed value is in the file (it may be a database of an older layout: no such tables yet).
    fn holds_sealed_values(conn: &Connection) -> bool {
        conn.query_row(
            "SELECT EXISTS (SELECT 1 FROM sessions WHERE app_name LIKE 'enc1:%' OR window_title LIKE 'enc1:%')
                 OR EXISTS (SELECT 1 FROM sync_queue WHERE payload LIKE 'enc1:%')",
            [],
            |row| row.get::<_, i64>(0),
        )
        .map(|found| found != 0)
        .unwrap_or(false)
    }

    /// One text column value as it is stored.
    fn seal_opt(&self, value: Option<&str>) -> Option<String> {
        value.map(|v| self.cipher.seal(v))
    }

    /// One text column value as it was written; a sealed value that cannot be read counts as missing.
    fn open_opt(&self, stored: Option<String>) -> Option<String> {
        stored.and_then(|v| self.cipher.open(&v))
    }

    /// Encrypts, once, what an older version stored in the clear: window titles, application names, the send queue,
    /// the personal details in `app_state` and the pictures waiting on disk. Safe to run again (sealed values are
    /// skipped). Afterwards the pre-migration copy (`tracker.db.bak`) is removed, since it holds the clear text, and
    /// the file is compacted so old pages with clear text do not linger in it.
    fn encrypt_existing(&self, db_path: Option<&Path>) -> rusqlite::Result<()> {
        if !self.cipher.enabled() || self.get_app_state("db_encrypted").as_deref() == Some("1") {
            return Ok(());
        }

        let tx = self.conn.unchecked_transaction()?;
        {
            let mut rows = tx.prepare("SELECT id, app_name, process_name, window_title, idle_app_name FROM sessions")?;
            let all: Vec<(String, [Option<String>; 4])> = rows
                .query_map([], |row| Ok((row.get(0)?, [row.get(1)?, row.get(2)?, row.get(3)?, row.get(4)?])))?
                .collect::<rusqlite::Result<_>>()?;
            for (id, fields) in all {
                let [app, process, title, idle] = fields.map(|f| f.map(|v| self.cipher.seal(&v)));
                tx.execute(
                    "UPDATE sessions SET app_name = ?1, process_name = ?2, window_title = ?3, idle_app_name = ?4 WHERE id = ?5",
                    params![app, process, title, idle, id],
                )?;
            }

            let mut queue = tx.prepare("SELECT id, payload FROM sync_queue")?;
            let all: Vec<(i64, String)> = queue
                .query_map([], |row| Ok((row.get(0)?, row.get(1)?)))?
                .collect::<rusqlite::Result<_>>()?;
            for (id, payload) in all {
                tx.execute("UPDATE sync_queue SET payload = ?1 WHERE id = ?2", params![self.cipher.seal(&payload), id])?;
            }

            for key in SEALED_STATE_KEYS {
                let value: Option<String> = tx
                    .query_row("SELECT value FROM app_state WHERE key = ?1", params![key], |row| row.get(0))
                    .ok();
                if let Some(value) = value {
                    tx.execute("UPDATE app_state SET value = ?1 WHERE key = ?2", params![self.cipher.seal(&value), key])?;
                }
            }
        }
        tx.execute(
            "INSERT INTO app_state (key, value) VALUES ('db_encrypted', '1') ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [],
        )?;
        tx.commit()?;

        // the pictures waiting to be sent
        if self.table_exists("screenshots")? {
            let paths: Vec<String> = {
                let mut stmt = self.conn.prepare("SELECT path FROM screenshots")?;
                let rows = stmt.query_map([], |row| row.get(0))?;
                rows.collect::<rusqlite::Result<_>>()?
            };
            for path in paths {
                if let Ok(bytes) = std::fs::read(&path) {
                    if !Cipher::is_sealed_file(&bytes) {
                        let _ = std::fs::write(&path, self.cipher.seal_bytes(&bytes));
                    }
                }
            }
        }

        if let Some(path) = db_path {
            let _ = std::fs::remove_file(path.with_file_name("tracker.db.bak"));
        }
        let _ = self.conn.execute_batch("PRAGMA wal_checkpoint(TRUNCATE); VACUUM;");
        Ok(())
    }

    /// For unit tests: skips the file path / corruption machinery entirely.
    pub fn open_in_memory_for_test() -> rusqlite::Result<Self> {
        let conn = Connection::open_in_memory()?;
        Self::configure(&conn)?;
        let db = Self { conn, cipher: Cipher::for_test() };
        db.migrate(None)?;
        Ok(db)
    }

    fn configure(conn: &Connection) -> rusqlite::Result<()> {
        conn.execute_batch("PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;")
    }

    fn table_exists(&self, name: &str) -> rusqlite::Result<bool> {
        let count: i64 = self.conn.query_row(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?1",
            params![name],
            |row| row.get(0),
        )?;
        Ok(count > 0)
    }

    /// Applies every migration newer than the current `schema_version`, each in its own
    /// transaction, copying the file to `tracker.db.bak` first if it already has data
    /// (a brand-new empty file has nothing worth backing up). On first run only, also
    /// seeds `device_id` / a placeholder `current_user_id` / `was_tracking` — real login
    /// (Phase 2) later overwrites `current_user_id` with the server-issued id via a
    /// plain `set_app_state` call, no schema change needed.
    fn migrate(&self, db_path: Option<&Path>) -> rusqlite::Result<()> {
        let had_app_state = self.table_exists("app_state")?;
        let current_version: i64 = if had_app_state {
            self.get_app_state("schema_version")
                .and_then(|v| v.parse().ok())
                .unwrap_or(0)
        } else {
            0
        };

        for &(version, sql) in MIGRATIONS {
            if version <= current_version {
                continue;
            }
            if had_app_state {
                if let Some(path) = db_path {
                    let _ = std::fs::copy(path, path.with_file_name("tracker.db.bak"));
                }
            }
            let tx = self.conn.unchecked_transaction()?;
            tx.execute_batch(sql)?;
            tx.execute(
                "INSERT INTO app_state (key, value) VALUES ('schema_version', ?1)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                params![version.to_string()],
            )?;
            tx.commit()?;
        }

        if !had_app_state {
            if self.get_app_state("device_id").is_none() {
                self.set_app_state("device_id", &uuid::Uuid::now_v7().to_string())?;
            }
            if self.get_app_state("current_user_id").is_none() {
                self.set_app_state(
                    "current_user_id",
                    &format!("local-{}", uuid::Uuid::now_v7()),
                )?;
            }
            if self.get_app_state("was_tracking").is_none() {
                self.set_app_state("was_tracking", "no")?;
            }
        }

        Ok(())
    }

    pub fn get_app_state(&self, key: &str) -> Option<String> {
        let stored: Option<String> = self
            .conn
            .query_row(
                "SELECT value FROM app_state WHERE key = ?1",
                params![key],
                |row| row.get(0),
            )
            .ok();
        if SEALED_STATE_KEYS.contains(&key) {
            self.open_opt(stored)
        } else {
            stored
        }
    }

    pub fn set_app_state(&self, key: &str, value: &str) -> rusqlite::Result<()> {
        let value = if SEALED_STATE_KEYS.contains(&key) { self.cipher.seal(value) } else { value.to_owned() };
        self.conn.execute(
            "INSERT INTO app_state (key, value) VALUES (?1, ?2)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            params![key, value],
        )?;
        Ok(())
    }

    pub fn open_session(&self, new: &NewSession) -> rusqlite::Result<String> {
        let created_at = chrono::Utc::now().timestamp_millis();
        self.conn.execute(
            "INSERT INTO sessions
                (id, user_id, device_id, session_type, app_name, process_name, window_title,
                 idle_app_name, started_at, ended_at, last_seen_at, duration_seconds,
                 clock_changed, sync_status, created_at, task_id)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, NULL, ?10, NULL, 0, 'OPEN', ?11, ?12)",
            params![
                new.id,
                new.user_id,
                new.device_id,
                new.session_type.as_str(),
                self.seal_opt(new.app_name.as_deref()),
                self.seal_opt(new.process_name.as_deref()),
                self.seal_opt(new.window_title.as_deref()),
                self.seal_opt(new.idle_app_name.as_deref()),
                new.started_at,
                new.last_seen_at,
                created_at,
                new.task_id,
            ],
        )?;
        Ok(new.id.clone())
    }

    /// One transaction: sets `ended_at`/`duration_seconds`/`sync_status = 'PENDING'` and
    /// queues the session for sync. Sessions under 1 second are deleted instead of
    /// saved — never queued (docs §6.2: "Sessions shorter than 1 second are deleted").
    pub fn close_session(
        &self,
        session_id: &str,
        ended_at_ms: i64,
        clock_changed: bool,
    ) -> rusqlite::Result<()> {
        self.close_session_with_stats(session_id, ended_at_ms, clock_changed, None)
    }

    /// Like `close_session`, with the counts of the input that happened during the session (they go to the server
    /// with it, and nowhere else).
    pub fn close_session_with_stats(
        &self,
        session_id: &str,
        ended_at_ms: i64,
        clock_changed: bool,
        stats: Option<&InputStats>,
    ) -> rusqlite::Result<()> {
        let stats = stats.filter(|s| !s.is_empty());
        let stats_json = stats.and_then(|s| serde_json::to_string(s).ok());
        let tx = self.conn.unchecked_transaction()?;

        let (started_at, user_id): (i64, String) = tx.query_row(
            "SELECT started_at, user_id FROM sessions WHERE id = ?1",
            params![session_id],
            |row| Ok((row.get(0)?, row.get(1)?)),
        )?;
        let duration_seconds = (ended_at_ms - started_at) / 1000;

        if duration_seconds < 1 {
            tx.execute("DELETE FROM sessions WHERE id = ?1", params![session_id])?;
            tx.commit()?;
            return Ok(());
        }

        tx.execute(
            "UPDATE sessions
             SET ended_at = ?1, duration_seconds = ?2, clock_changed = ?3, sync_status = 'PENDING', input_stats = ?5
             WHERE id = ?4",
            params![ended_at_ms, duration_seconds, clock_changed as i64, session_id, stats_json],
        )?;

        let payload = tx.query_row(
            "SELECT session_type, app_name, process_name, window_title, idle_app_name, started_at, task_id
             FROM sessions WHERE id = ?1",
            params![session_id],
            |row| {
                Ok(SyncPayload {
                    id: session_id.to_string(),
                    session_type: row.get(0)?,
                    app_name: self.open_opt(row.get(1)?),
                    process_name: self.open_opt(row.get(2)?),
                    window_title: self.open_opt(row.get(3)?),
                    idle_app_name: self.open_opt(row.get(4)?),
                    started_at: row.get::<_, i64>(5)?,
                    ended_at: ended_at_ms,
                    duration_seconds,
                    clock_changed,
                    input_stats: stats.cloned(),
                    task_id: row.get(6)?,
                })
            },
        )?;
        let payload_json = serde_json::to_string(&payload).unwrap_or_default();
        let now = chrono::Utc::now().timestamp_millis();
        tx.execute(
            "INSERT INTO sync_queue (entity_type, entity_id, user_id, payload, next_attempt_at, created_at)
             VALUES ('SESSION', ?1, ?2, ?3, ?4, ?5)",
            params![session_id, user_id, self.cipher.seal(&payload_json), now, now],
        )?;

        tx.commit()
    }

    pub fn update_session_title(
        &self,
        session_id: &str,
        window_title: Option<&str>,
    ) -> rusqlite::Result<()> {
        self.conn.execute(
            "UPDATE sessions SET window_title = ?1 WHERE id = ?2",
            params![self.seal_opt(window_title), session_id],
        )?;
        Ok(())
    }

    pub fn touch_last_seen(&self, session_id: &str, last_seen_at_ms: i64) -> rusqlite::Result<()> {
        self.conn.execute(
            "UPDATE sessions SET last_seen_at = ?1 WHERE id = ?2",
            params![last_seen_at_ms, session_id],
        )?;
        Ok(())
    }

    /// Crash recovery (task 6): every session left open by a previous run gets
    /// `ended_at = last_seen_at` — at most ~30s of the crashed session is lost.
    pub fn close_all_open_sessions_at_last_seen(&self) -> rusqlite::Result<Vec<String>> {
        let ids: Vec<(String, i64)> = {
            let mut stmt = self
                .conn
                .prepare("SELECT id, last_seen_at FROM sessions WHERE ended_at IS NULL")?;
            let rows = stmt.query_map([], |row| Ok((row.get(0)?, row.get(1)?)))?;
            rows.collect::<rusqlite::Result<Vec<_>>>()?
        };

        let mut closed = Vec::with_capacity(ids.len());
        for (id, last_seen_at) in ids {
            self.close_session(&id, last_seen_at, false)?;
            closed.push(id);
        }
        Ok(closed)
    }

    pub fn sessions_for_range(
        &self,
        user_id: &str,
        start_ms: i64,
        end_ms: i64,
    ) -> rusqlite::Result<Vec<SessionRow>> {
        let mut stmt = self.conn.prepare(
            "SELECT id, session_type, app_name, idle_app_name, started_at, ended_at,
                    duration_seconds, sync_status, task_id
             FROM sessions
             WHERE user_id = ?1 AND started_at >= ?2 AND started_at < ?3
             ORDER BY started_at",
        )?;
        let rows = stmt.query_map(params![user_id, start_ms, end_ms], |row| {
            Ok(SessionRow {
                id: row.get(0)?,
                session_type: SessionType::from_str(&row.get::<_, String>(1)?),
                app_name: self.open_opt(row.get(2)?),
                idle_app_name: self.open_opt(row.get(3)?),
                started_at: row.get(4)?,
                ended_at: row.get(5)?,
                duration_seconds: row.get(6)?,
                sync_status: row.get(7)?,
                task_id: row.get(8)?,
            })
        })?;
        rows.collect()
    }

    /// Once-a-day housekeeping: only `SYNCED` sessions and `DONE` queue rows are ever
    /// deleted automatically, and only once older than `older_than_ms`. Unsent
    /// (`OPEN`/`PENDING`/`FAILED`) data is never touched.
    pub fn purge_old_synced(&self, older_than_ms: i64) -> rusqlite::Result<()> {
        self.conn.execute(
            "DELETE FROM sessions WHERE sync_status = 'SYNCED' AND ended_at < ?1",
            params![older_than_ms],
        )?;
        self.conn.execute(
            "DELETE FROM sync_queue WHERE status = 'DONE' AND created_at < ?1",
            params![older_than_ms],
        )?;
        Ok(())
    }

    /// First login on this database only: everything recorded before login, under the
    /// `local-<uuid>` placeholder id, now belongs to the person who logged in. Runs once
    /// (`placeholder_adopted`); later logins by anyone never touch it. Returns how many
    /// sessions were re-keyed.
    pub fn adopt_placeholder_user(&self, real_user_id: &str) -> rusqlite::Result<usize> {
        if self.get_app_state("placeholder_adopted").as_deref() == Some("1") {
            return Ok(0);
        }
        let tx = self.conn.unchecked_transaction()?;
        let sessions = tx.execute(
            "UPDATE sessions SET user_id = ?1 WHERE user_id LIKE 'local-%'",
            params![real_user_id],
        )?;
        tx.execute(
            "UPDATE sync_queue SET user_id = ?1 WHERE user_id LIKE 'local-%'",
            params![real_user_id],
        )?;
        tx.execute(
            "INSERT INTO app_state (key, value) VALUES ('placeholder_adopted', '1')
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [],
        )?;
        tx.commit()?;
        Ok(sessions)
    }

    /// Queue rows for this user that still need sending.
    pub fn pending_count(&self, user_id: &str) -> rusqlite::Result<i64> {
        self.conn.query_row(
            "SELECT COUNT(*) FROM sync_queue WHERE user_id = ?1 AND status = 'PENDING'",
            params![user_id],
            |row| row.get(0),
        )
    }

    /// Up to `limit` rows for this user that are due, oldest first (docs §11.1). Rows of
    /// any other user are never returned: they wait for that user to log in again.
    pub fn fetch_ready_queue(
        &self,
        user_id: &str,
        limit: usize,
        now_ms: i64,
    ) -> rusqlite::Result<Vec<QueueRow>> {
        let mut stmt = self.conn.prepare(
            "SELECT entity_id, payload FROM sync_queue
             WHERE user_id = ?1 AND status = 'PENDING' AND next_attempt_at <= ?2
             ORDER BY id LIMIT ?3",
        )?;
        let rows = stmt.query_map(params![user_id, now_ms, limit as i64], |row| {
            Ok(QueueRow {
                entity_id: row.get(0)?,
                payload: self.cipher.open(&row.get::<_, String>(1)?).unwrap_or_default(),
            })
        })?;
        rows.collect()
    }

    /// Server has the session (accepted or already had it): queue row `DONE`, session `SYNCED`.
    pub fn mark_synced(&self, entity_id: &str) -> rusqlite::Result<()> {
        let tx = self.conn.unchecked_transaction()?;
        tx.execute(
            "UPDATE sync_queue SET status = 'DONE', last_error = NULL WHERE entity_id = ?1",
            params![entity_id],
        )?;
        tx.execute(
            "UPDATE sessions SET sync_status = 'SYNCED' WHERE id = ?1",
            params![entity_id],
        )?;
        tx.commit()
    }

    /// Server refused the session for good: queue row `FAILED`, session `REJECTED`.
    pub fn mark_rejected(&self, entity_id: &str, reason: &str) -> rusqlite::Result<()> {
        let tx = self.conn.unchecked_transaction()?;
        tx.execute(
            "UPDATE sync_queue SET status = 'FAILED', last_error = ?2 WHERE entity_id = ?1",
            params![entity_id, reason],
        )?;
        tx.execute(
            "UPDATE sessions SET sync_status = 'REJECTED' WHERE id = ?1",
            params![entity_id],
        )?;
        tx.commit()
    }

    /// A send failed for a temporary reason: try again after the backoff for this row's
    /// attempt count (docs §11.1: 1, 2, 5, 10, then 30 minutes).
    pub fn schedule_retry(&self, entity_id: &str, error: &str, now_ms: i64) -> rusqlite::Result<()> {
        let attempts: i64 = self.conn.query_row(
            "SELECT attempts FROM sync_queue WHERE entity_id = ?1",
            params![entity_id],
            |row| row.get(0),
        )?;
        self.conn.execute(
            "UPDATE sync_queue
             SET attempts = attempts + 1, last_attempt_at = ?2, next_attempt_at = ?3, last_error = ?4
             WHERE entity_id = ?1",
            params![entity_id, now_ms, now_ms + retry_delay_ms(attempts), error],
        )?;
        Ok(())
    }

    /// "The internet is back": make everything due right now instead of waiting out backoff.
    pub fn make_pending_due(&self, user_id: &str, now_ms: i64) -> rusqlite::Result<()> {
        self.conn.execute(
            "UPDATE sync_queue SET next_attempt_at = ?2 WHERE user_id = ?1 AND status = 'PENDING'",
            params![user_id, now_ms],
        )?;
        Ok(())
    }

    // ---- screenshots waiting to be sent (docs phase 10) --------------------------------------------

    pub fn add_screenshot(&self, row: &ScreenshotRow, now_ms: i64) -> rusqlite::Result<()> {
        self.conn.execute(
            "INSERT INTO screenshots (id, user_id, taken_at, path, width, height, attempts, next_attempt_at, created_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, 0, 0, ?7)",
            params![row.id, row.user_id, row.taken_at, row.path, row.width, row.height, now_ms],
        )?;
        Ok(())
    }

    /// The oldest screenshot of this user that is due to be sent. Other users' rows are never returned:
    /// they wait for that person to log in again.
    pub fn next_screenshot_to_send(&self, user_id: &str, now_ms: i64) -> rusqlite::Result<Option<ScreenshotRow>> {
        let mut stmt = self.conn.prepare(
            "SELECT id, user_id, taken_at, path, width, height, attempts FROM screenshots
             WHERE user_id = ?1 AND next_attempt_at <= ?2 ORDER BY taken_at LIMIT 1",
        )?;
        let mut rows = stmt.query_map(params![user_id, now_ms], |row| {
            Ok(ScreenshotRow {
                id: row.get(0)?,
                user_id: row.get(1)?,
                taken_at: row.get(2)?,
                path: row.get(3)?,
                width: row.get(4)?,
                height: row.get(5)?,
                attempts: row.get(6)?,
            })
        })?;
        rows.next().transpose()
    }

    pub fn pending_screenshot_count(&self, user_id: &str) -> rusqlite::Result<i64> {
        self.conn.query_row("SELECT COUNT(*) FROM screenshots WHERE user_id = ?1", params![user_id], |row| row.get(0))
    }

    /// Removes the row once the server has the picture (or will never take it).
    pub fn delete_screenshot(&self, id: &str) -> rusqlite::Result<()> {
        self.conn.execute("DELETE FROM screenshots WHERE id = ?1", params![id])?;
        Ok(())
    }

    /// One more failed try: wait longer before the next (same waiting times as sessions).
    pub fn schedule_screenshot_retry(&self, id: &str, now_ms: i64) -> rusqlite::Result<()> {
        let attempts: i64 = self.conn.query_row("SELECT attempts FROM screenshots WHERE id = ?1", params![id], |row| row.get(0))?;
        self.conn.execute(
            "UPDATE screenshots SET attempts = attempts + 1, next_attempt_at = ?2 WHERE id = ?1",
            params![id, now_ms + retry_delay_ms(attempts)],
        )?;
        Ok(())
    }

    /// "The internet is back": every waiting screenshot of this user is due now.
    pub fn make_screenshots_due(&self, user_id: &str, now_ms: i64) -> rusqlite::Result<()> {
        self.conn.execute("UPDATE screenshots SET next_attempt_at = ?2 WHERE user_id = ?1", params![user_id, now_ms])?;
        Ok(())
    }

    /// Keeps at most `keep` waiting screenshots for this user, dropping the oldest. Returns the files that
    /// belonged to the dropped rows, for the caller to delete (a long offline spell must not fill the disk).
    pub fn trim_screenshots(&self, user_id: &str, keep: usize) -> rusqlite::Result<Vec<String>> {
        let mut stmt = self.conn.prepare(
            "SELECT id, path FROM screenshots WHERE user_id = ?1 ORDER BY taken_at DESC LIMIT -1 OFFSET ?2",
        )?;
        let dropped: Vec<(String, String)> = stmt
            .query_map(params![user_id, keep as i64], |row| Ok((row.get(0)?, row.get(1)?)))?
            .collect::<rusqlite::Result<_>>()?;
        for (id, _) in &dropped {
            self.delete_screenshot(id)?;
        }
        Ok(dropped.into_iter().map(|(_, path)| path).collect())
    }
}

/// One row of `sync_queue` waiting to be sent.
#[derive(Debug, Clone)]
pub struct QueueRow {
    pub entity_id: String,
    pub payload: String,
}

/// Wait before retrying a row that has failed `attempts` times so far.
pub fn retry_delay_ms(attempts: i64) -> i64 {
    const MINUTES: [i64; 5] = [1, 2, 5, 10, 30];
    MINUTES[attempts.clamp(0, 4) as usize] * 60_000
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn migration_two_lowercases_stored_enum_values() {
        // a database as an older build left it: migration 1 only, with uppercase values
        let conn = rusqlite::Connection::open_in_memory().unwrap();
        conn.execute_batch(include_str!("migrations/001_init.sql")).unwrap();
        conn.execute_batch(
            r#"INSERT INTO sessions (id, user_id, device_id, session_type, app_name, started_at, last_seen_at, sync_status, created_at)
                 VALUES ('a', '1', 'd', 'APPLICATION', 'Code', 1, 1, 'SYNCED', 1),
                        ('b', '1', 'd', 'IDLE', NULL, 2, 2, 'PENDING', 2);
               INSERT INTO sync_queue (entity_type, entity_id, user_id, payload, next_attempt_at, created_at)
                 VALUES ('SESSION', 'b', '1', '{"id":"b","type":"IDLE","appName":null}', 0, 0);
               INSERT INTO app_state (key, value) VALUES
                 ('me_json', '{"id":"1","role":"DEVELOPER","status":"DEACTIVATED","officeSettings":{"windowTitleMode":"APP_ONLY"}}'),
                 ('office_settings_json', '{"idleThresholdSeconds":300,"windowTitleMode":"FULL"}');"#,
        )
        .unwrap();

        conn.execute_batch(include_str!("migrations/002_lowercase_enums.sql")).unwrap();

        let types: Vec<String> = conn
            .prepare("SELECT session_type FROM sessions ORDER BY id").unwrap()
            .query_map([], |r| r.get(0)).unwrap().map(Result::unwrap).collect();
        assert_eq!(types, ["application", "idle"]);
        let payload: String = conn.query_row("SELECT payload FROM sync_queue", [], |r| r.get(0)).unwrap();
        assert!(payload.contains(r#""type":"idle""#), "{payload}");
        let me: String = conn.query_row("SELECT value FROM app_state WHERE key = 'me_json'", [], |r| r.get(0)).unwrap();
        assert!(me.contains(r#""role":"developer""#) && me.contains(r#""status":"inactive""#) && me.contains(r#""windowTitleMode":"app_only""#), "{me}");
        let office: String = conn.query_row("SELECT value FROM app_state WHERE key = 'office_settings_json'", [], |r| r.get(0)).unwrap();
        assert!(office.contains(r#""windowTitleMode":"full""#), "{office}");
        // the new check constraint only accepts the lowercase values
        assert!(conn.execute("UPDATE sessions SET session_type = 'IDLE' WHERE id = 'a'", []).is_err());
    }

    fn closed_session(db: &Db, user_id: &str) -> String {
        let id = db
            .open_session(&NewSession {
                id: uuid::Uuid::now_v7().to_string(),
                user_id: user_id.into(),
                device_id: "dev".into(),
                session_type: SessionType::Application,
                app_name: Some("Code".into()),
                process_name: Some("Code.exe".into()),
                window_title: None,
                idle_app_name: None,
                started_at: 1_000,
                last_seen_at: 1_000,
                task_id: None,
            })
            .unwrap();
        db.close_session(&id, 6_000, false).unwrap();
        id
    }

    #[test]
    fn first_login_adopts_placeholder_sessions_and_queue_rows() {
        let db = Db::open_in_memory_for_test().unwrap();
        let placeholder = db.get_app_state("current_user_id").unwrap();
        assert!(placeholder.starts_with("local-"));
        closed_session(&db, &placeholder);
        closed_session(&db, &placeholder);

        assert_eq!(db.adopt_placeholder_user("42").unwrap(), 2);

        assert_eq!(db.pending_count("42").unwrap(), 2);
        assert_eq!(db.pending_count(&placeholder).unwrap(), 0);
        let rows = db.sessions_for_range("42", 0, 10_000).unwrap();
        assert_eq!(rows.len(), 2);
    }

    #[test]
    fn adoption_happens_only_once() {
        let db = Db::open_in_memory_for_test().unwrap();
        let placeholder = db.get_app_state("current_user_id").unwrap();
        closed_session(&db, &placeholder);
        db.adopt_placeholder_user("42").unwrap();

        // Stray placeholder data recorded later must not be handed to whoever logs in next.
        closed_session(&db, &placeholder);
        assert_eq!(db.adopt_placeholder_user("77").unwrap(), 0);
        assert_eq!(db.pending_count("77").unwrap(), 0);
        assert_eq!(db.pending_count("42").unwrap(), 1);
    }

    #[test]
    fn queue_only_returns_this_users_due_rows_oldest_first() {
        let db = Db::open_in_memory_for_test().unwrap();
        let first = closed_session(&db, "1");
        let _other_user = closed_session(&db, "2");
        let second = closed_session(&db, "1");

        let ready = db.fetch_ready_queue("1", 100, i64::MAX).unwrap();
        assert_eq!(
            ready.iter().map(|r| r.entity_id.as_str()).collect::<Vec<_>>(),
            vec![first.as_str(), second.as_str()]
        );
        assert_eq!(db.fetch_ready_queue("1", 1, i64::MAX).unwrap().len(), 1);
        assert!(db.fetch_ready_queue("1", 100, 0).unwrap().is_empty());
    }

    #[test]
    fn synced_and_rejected_rows_leave_the_queue_and_mark_the_session() {
        let db = Db::open_in_memory_for_test().unwrap();
        let ok = closed_session(&db, "1");
        let bad = closed_session(&db, "1");

        db.mark_synced(&ok).unwrap();
        db.mark_rejected(&bad, "TOO_OLD").unwrap();

        assert_eq!(db.pending_count("1").unwrap(), 0);
        let rows = db.sessions_for_range("1", 0, 10_000).unwrap();
        let status = |id: &str| rows.iter().find(|r| r.id == id).unwrap().sync_status.clone();
        assert_eq!(status(&ok), "SYNCED");
        assert_eq!(status(&bad), "REJECTED");
    }

    #[test]
    fn retries_back_off_1_2_5_10_then_30_minutes_and_can_be_made_due_again() {
        assert_eq!(
            (0..7).map(|a| retry_delay_ms(a) / 60_000).collect::<Vec<_>>(),
            vec![1, 2, 5, 10, 30, 30, 30]
        );

        let db = Db::open_in_memory_for_test().unwrap();
        let id = closed_session(&db, "1");
        let now = 1_000_000;

        db.schedule_retry(&id, "offline", now).unwrap();
        assert!(db.fetch_ready_queue("1", 10, now + 59_000).unwrap().is_empty());
        assert_eq!(db.fetch_ready_queue("1", 10, now + 60_000).unwrap().len(), 1);

        db.schedule_retry(&id, "offline", now).unwrap(); // second failure: 2 minutes
        assert!(db.fetch_ready_queue("1", 10, now + 119_000).unwrap().is_empty());

        db.make_pending_due("1", now).unwrap();
        assert_eq!(db.fetch_ready_queue("1", 10, now).unwrap().len(), 1);
    }

    #[test]
    fn other_users_data_is_never_adopted() {
        let db = Db::open_in_memory_for_test().unwrap();
        closed_session(&db, "7");

        assert_eq!(db.adopt_placeholder_user("42").unwrap(), 0);
        assert_eq!(db.pending_count("7").unwrap(), 1);
    }

    fn shot(id: &str, user: &str, taken_at: i64) -> ScreenshotRow {
        ScreenshotRow {
            id: id.into(),
            user_id: user.into(),
            taken_at,
            path: format!("{id}.jpg"),
            width: 1280,
            height: 720,
            attempts: 0,
        }
    }

    #[test]
    fn screenshots_are_sent_oldest_first_and_only_for_their_own_user() {
        let db = Db::open_in_memory_for_test().unwrap();
        db.add_screenshot(&shot("b", "1", 200), 0).unwrap();
        db.add_screenshot(&shot("a", "1", 100), 0).unwrap();
        db.add_screenshot(&shot("x", "2", 50), 0).unwrap();

        assert_eq!(db.next_screenshot_to_send("1", 0).unwrap().unwrap().id, "a");
        db.delete_screenshot("a").unwrap();
        assert_eq!(db.next_screenshot_to_send("1", 0).unwrap().unwrap().id, "b");
        db.delete_screenshot("b").unwrap();
        assert!(db.next_screenshot_to_send("1", 0).unwrap().is_none(), "the other user's row is not returned");
        assert_eq!(db.pending_screenshot_count("2").unwrap(), 1, "and it is kept for when they log in again");
    }

    #[test]
    fn a_failed_screenshot_waits_longer_each_time_and_is_due_again_when_the_internet_is_back() {
        let db = Db::open_in_memory_for_test().unwrap();
        db.add_screenshot(&shot("a", "1", 100), 0).unwrap();
        let now = 1_000_000;

        db.schedule_screenshot_retry("a", now).unwrap();
        assert!(db.next_screenshot_to_send("1", now + 59_000).unwrap().is_none());
        let due = db.next_screenshot_to_send("1", now + 60_000).unwrap().unwrap();
        assert_eq!(due.attempts, 1);

        db.schedule_screenshot_retry("a", now).unwrap(); // second failure: two minutes
        assert!(db.next_screenshot_to_send("1", now + 119_000).unwrap().is_none());

        db.make_screenshots_due("1", now).unwrap();
        assert!(db.next_screenshot_to_send("1", now).unwrap().is_some());
    }

    #[test]
    fn too_many_waiting_screenshots_drop_the_oldest_and_report_their_files() {
        let db = Db::open_in_memory_for_test().unwrap();
        for i in 0..5 {
            db.add_screenshot(&shot(&format!("s{i}"), "1", i * 10), 0).unwrap();
        }
        db.add_screenshot(&shot("other", "2", 1), 0).unwrap();

        let dropped = db.trim_screenshots("1", 3).unwrap();

        assert_eq!(dropped, vec!["s1.jpg".to_owned(), "s0.jpg".to_owned()]);
        assert_eq!(db.pending_screenshot_count("1").unwrap(), 3);
        assert_eq!(db.next_screenshot_to_send("1", 0).unwrap().unwrap().id, "s2", "the oldest that is left");
        assert_eq!(db.pending_screenshot_count("2").unwrap(), 1, "another user's rows are never trimmed");
        assert!(db.trim_screenshots("1", 3).unwrap().is_empty());
    }

    #[test]
    fn migration_three_creates_the_screenshot_queue_and_keeps_existing_data() {
        let conn = rusqlite::Connection::open_in_memory().unwrap();
        conn.execute_batch(include_str!("migrations/001_init.sql")).unwrap();
        conn.execute_batch(include_str!("migrations/002_lowercase_enums.sql")).unwrap();
        conn.execute("INSERT INTO app_state (key, value) VALUES ('device_id', 'd1')", []).unwrap();

        conn.execute_batch(include_str!("migrations/003_screenshots.sql")).unwrap();

        let device: String = conn.query_row("SELECT value FROM app_state WHERE key = 'device_id'", [], |r| r.get(0)).unwrap();
        assert_eq!(device, "d1");
        conn.execute("INSERT INTO screenshots (id, user_id, taken_at, path, width, height, created_at) VALUES ('a', '1', 1, 'a.jpg', 1, 1, 1)", []).unwrap();
    }
    // ---- encryption of what is stored (docs/SECURITY_REVIEW.md) --------------------------------------------------

    /// A folder for one test, removed afterwards.
    struct TempDir(std::path::PathBuf);

    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!("tracker-db-test-{}", uuid::Uuid::now_v7()));
            std::fs::create_dir_all(&path).unwrap();
            Self(path)
        }

        fn db_path(&self) -> std::path::PathBuf {
            self.0.join("tracker.db")
        }
    }

    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    fn titled_session(db: &Db, user_id: &str, title: &str) -> String {
        let id = db
            .open_session(&NewSession {
                id: uuid::Uuid::now_v7().to_string(),
                user_id: user_id.into(),
                device_id: "dev".into(),
                session_type: SessionType::Application,
                app_name: Some("Excel".into()),
                process_name: Some("EXCEL.EXE".into()),
                window_title: Some(title.into()),
                idle_app_name: None,
                started_at: 1_000,
                last_seen_at: 1_000,
                task_id: None,
            })
            .unwrap();
        db.close_session(&id, 6_000, false).unwrap();
        id
    }

    /// what a second connection sees in the file, without the key
    fn raw(dir: &TempDir, sql: &str) -> Vec<String> {
        let conn = rusqlite::Connection::open(dir.db_path()).unwrap();
        let mut stmt = conn.prepare(sql).unwrap();
        stmt.query_map([], |row| row.get::<_, Option<String>>(0)).unwrap().map(|v| v.unwrap().unwrap_or_default()).collect()
    }

    #[test]
    fn titles_names_and_the_queue_are_stored_encrypted_and_read_back_whole() {
        let dir = TempDir::new();
        let db = Db::open(&dir.db_path(), Cipher::for_test(), false).unwrap();
        let id = titled_session(&db, "1", "Budget 2027 - Excel");

        for column in ["app_name", "process_name", "window_title"] {
            let stored = raw(&dir, &format!("SELECT {column} FROM sessions"));
            assert!(stored[0].starts_with("enc1:"), "{column}: {}", stored[0]);
        }
        let payload = &raw(&dir, "SELECT payload FROM sync_queue")[0];
        assert!(payload.starts_with("enc1:") && !payload.contains("Budget"), "{payload}");

        // through the app's own methods everything is as before
        let rows = db.sessions_for_range("1", 0, 10_000).unwrap();
        assert_eq!(rows[0].app_name.as_deref(), Some("Excel"));
        let queue = db.fetch_ready_queue("1", 10, i64::MAX).unwrap();
        assert!(queue[0].payload.contains(r#""windowTitle":"Budget 2027 - Excel""#), "{}", queue[0].payload);
        assert!(queue[0].payload.contains(&id));

        // the whole file: no clear text anywhere in it or its write-ahead log
        drop(db);
        let mut bytes = std::fs::read(dir.db_path()).unwrap();
        if let Ok(wal) = std::fs::read(dir.0.join("tracker.db-wal")) {
            bytes.extend(wal);
        }
        assert!(!bytes.windows(6).any(|w| w == b"Budget"), "the title is readable in the file");
        assert!(!bytes.windows(9).any(|w| w == b"EXCEL.EXE"), "the process name is readable in the file");
    }

    #[test]
    fn an_old_clear_text_database_is_encrypted_in_place_and_keeps_working() {
        let dir = TempDir::new();
        {
            // what the previous version wrote: the same file, no encryption
            let old = Db::open(&dir.db_path(), Cipher::disabled(), false).unwrap();
            titled_session(&old, "1", "Salary list.xlsx");
            old.set_app_state("me_json", r#"{"name":"Ana Cruz"}"#).unwrap();
            assert!(!Db::holds_sealed_values(&old.conn));
        }
        assert!(raw(&dir, "SELECT window_title FROM sessions")[0] == "Salary list.xlsx");
        std::fs::write(dir.0.join("tracker.db.bak"), b"clear copy").unwrap();

        let db = Db::open(&dir.db_path(), Cipher::for_test(), false).unwrap();

        assert!(raw(&dir, "SELECT window_title FROM sessions")[0].starts_with("enc1:"));
        assert!(raw(&dir, "SELECT payload FROM sync_queue")[0].starts_with("enc1:"));
        assert!(raw(&dir, "SELECT value FROM app_state WHERE key = 'me_json'")[0].starts_with("enc1:"));
        assert_eq!(db.get_app_state("me_json").as_deref(), Some(r#"{"name":"Ana Cruz"}"#));
        assert_eq!(db.get_app_state("db_encrypted").as_deref(), Some("1"));
        assert!(!dir.0.join("tracker.db.bak").exists(), "the copy made before the upgrade holds clear text");
        assert!(db.fetch_ready_queue("1", 10, i64::MAX).unwrap()[0].payload.contains("Salary list.xlsx"));
        drop(db);
        let bytes = std::fs::read(dir.db_path()).unwrap();
        assert!(!bytes.windows(6).any(|w| w == b"Salary"), "old pages with the title are still in the file");
    }

    #[test]
    fn opening_it_again_changes_nothing() {
        let dir = TempDir::new();
        let db = Db::open(&dir.db_path(), Cipher::for_test(), false).unwrap();
        titled_session(&db, "1", "Notes");
        let before = raw(&dir, "SELECT window_title FROM sessions");
        drop(db);

        let db = Db::open(&dir.db_path(), Cipher::for_test(), false).unwrap();
        assert_eq!(raw(&dir, "SELECT window_title FROM sessions"), before);
        assert_eq!(db.fetch_ready_queue("1", 10, i64::MAX).unwrap().len(), 1);
    }

    #[test]
    fn a_new_key_over_encrypted_data_starts_a_fresh_file_and_tells_the_server() {
        let dir = TempDir::new();
        {
            let db = Db::open(&dir.db_path(), Cipher::for_test(), false).unwrap();
            titled_session(&db, "1", "Notes");
        }

        // Credential Manager was wiped: a new key is made, the old values can never be read again
        let db = Db::open(&dir.db_path(), Cipher::from_key([9u8; 32]), true).unwrap();

        assert_eq!(db.sessions_for_range("1", 0, 10_000).unwrap().len(), 0);
        assert_eq!(db.get_app_state("db_reset_pending").as_deref(), Some("1"));
        let kept = std::fs::read_dir(&dir.0).unwrap().filter_map(Result::ok).any(|e| e.file_name().to_string_lossy().starts_with("tracker.db.corrupt-"));
        assert!(kept, "the old file is kept, not deleted");
    }

    #[test]
    fn a_new_key_over_an_empty_or_clear_text_file_is_no_loss() {
        let dir = TempDir::new();
        {
            let old = Db::open(&dir.db_path(), Cipher::disabled(), false).unwrap();
            titled_session(&old, "1", "Notes");
        }

        // first run of the encrypting version: the key is new, but nothing was encrypted with another one
        let db = Db::open(&dir.db_path(), Cipher::for_test(), true).unwrap();

        assert_eq!(db.sessions_for_range("1", 0, 10_000).unwrap().len(), 1);
        assert_ne!(db.get_app_state("db_reset_pending").as_deref(), Some("1"));
    }

    #[test]
    fn waiting_pictures_on_disk_are_encrypted_by_the_upgrade() {
        let dir = TempDir::new();
        let picture = dir.0.join("a.jpg");
        let jpeg = [0xFF, 0xD8, 0xFF, 0xE0, 9, 9, 9, 9];
        std::fs::write(&picture, jpeg).unwrap();
        {
            let old = Db::open(&dir.db_path(), Cipher::disabled(), false).unwrap();
            old.add_screenshot(
                &ScreenshotRow { id: "a".into(), user_id: "1".into(), taken_at: 1, path: picture.to_string_lossy().into_owned(), width: 1, height: 1, attempts: 0 },
                0,
            )
            .unwrap();
        }

        let db = Db::open(&dir.db_path(), Cipher::for_test(), false).unwrap();

        let stored = std::fs::read(&picture).unwrap();
        assert!(Cipher::is_sealed_file(&stored));
        assert_eq!(db.cipher().open_bytes(&stored).as_deref(), Some(&jpeg[..]));
    }

    // ---- tasks (docs/DEVELOPMENT_PLAN.md) -----------------------------------------------------------------------

    #[test]
    fn a_sessions_task_id_round_trips_in_the_clear_and_is_carried_into_the_sync_payload() {
        let dir = TempDir::new();
        let db = Db::open(&dir.db_path(), Cipher::for_test(), false).unwrap();
        let id = db
            .open_session(&NewSession {
                id: uuid::Uuid::now_v7().to_string(),
                user_id: "1".into(),
                device_id: "dev".into(),
                session_type: SessionType::Application,
                app_name: Some("Excel".into()),
                process_name: Some("EXCEL.EXE".into()),
                window_title: None,
                idle_app_name: None,
                started_at: 1_000,
                last_seen_at: 1_000,
                task_id: Some("42".into()),
            })
            .unwrap();
        db.close_session(&id, 6_000, false).unwrap();

        // unlike a window title, an opaque task id is not sealed: local grouping/filtering needs it in the clear
        let stored = raw(&dir, "SELECT task_id FROM sessions");
        assert_eq!(stored[0], "42");

        let queue = db.fetch_ready_queue("1", 10, i64::MAX).unwrap();
        assert!(queue[0].payload.contains(r#""taskId":"42""#), "{}", queue[0].payload);
    }

    #[test]
    fn a_session_with_no_task_carries_no_task_id() {
        let db = Db::open_in_memory_for_test().unwrap();
        let id = closed_session(&db, "1");

        let queue = db.fetch_ready_queue("1", 10, i64::MAX).unwrap();
        assert!(!queue.iter().any(|r| r.payload.contains("taskId")) || queue[0].payload.contains(r#""taskId":null"#), "{}", queue[0].payload);
        let rows = db.sessions_for_range("1", 0, 10_000).unwrap();
        assert_eq!(rows.iter().find(|r| r.id == id).unwrap().task_id, None);
    }

    #[test]
    fn migration_five_adds_task_id_as_null_to_existing_rows() {
        let conn = rusqlite::Connection::open_in_memory().unwrap();
        conn.execute_batch(include_str!("migrations/001_init.sql")).unwrap();
        conn.execute_batch(include_str!("migrations/002_lowercase_enums.sql")).unwrap();
        conn.execute_batch(include_str!("migrations/003_screenshots.sql")).unwrap();
        conn.execute_batch(include_str!("migrations/004_input_stats.sql")).unwrap();
        conn.execute(
            "INSERT INTO sessions (id, user_id, device_id, session_type, started_at, last_seen_at, sync_status, created_at)
             VALUES ('a', '1', 'd', 'application', 1, 1, 'SYNCED', 1)",
            [],
        )
        .unwrap();

        conn.execute_batch(include_str!("migrations/005_tasks.sql")).unwrap();

        let task_id: Option<String> = conn.query_row("SELECT task_id FROM sessions WHERE id = 'a'", [], |r| r.get(0)).unwrap();
        assert_eq!(task_id, None);
    }
}
