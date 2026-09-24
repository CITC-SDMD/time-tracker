//! Local SQLite storage (docs/DEVELOPMENT_PLAN.md §7). Owns the connection and every
//! SQL statement — `tracker::engine` never touches `rusqlite` directly, it only calls
//! the typed methods here. All times are stored as UTC milliseconds (`i64`).

use std::path::Path;

use rusqlite::{params, Connection};
use serde::Serialize;

/// (schema_version, migration SQL). Only one migration exists so far; future ones are
/// appended here and applied in order, each preceded by a `tracker.db.bak` copy.
const MIGRATIONS: &[(i64, &str)] = &[(1, include_str!("migrations/001_init.sql"))];

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SessionType {
    Application,
    Idle,
}

impl SessionType {
    fn as_str(self) -> &'static str {
        match self {
            Self::Application => "APPLICATION",
            Self::Idle => "IDLE",
        }
    }

    fn from_str(s: &str) -> Self {
        match s {
            "IDLE" => Self::Idle,
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
}

pub struct Db {
    conn: Connection,
}

impl Db {
    /// Opens (creating if absent) the database at `db_path`, verifies integrity, and
    /// runs any pending migrations. A failed integrity check quarantines the old file
    /// (renamed `tracker.db.corrupt-<unix_ms>`) and starts fresh — see docs §7.4.
    pub fn open(db_path: &Path) -> rusqlite::Result<Self> {
        let conn = Connection::open(db_path)?;
        Self::configure(&conn)?;

        let quick_check: String = conn.query_row("PRAGMA quick_check", [], |row| row.get(0))?;
        if quick_check != "ok" {
            drop(conn);
            let corrupt_path = db_path.with_file_name(format!(
                "tracker.db.corrupt-{}",
                chrono::Utc::now().timestamp_millis()
            ));
            let _ = std::fs::rename(db_path, &corrupt_path);
            tracing::error!(
                corrupt_path = %corrupt_path.display(),
                "tracker.db failed PRAGMA quick_check; quarantined and recreated"
            );

            let conn = Connection::open(db_path)?;
            Self::configure(&conn)?;
            let db = Self { conn };
            db.migrate(Some(db_path))?;
            return Ok(db);
        }

        let db = Self { conn };
        db.migrate(Some(db_path))?;
        Ok(db)
    }

    /// For unit tests: skips the file path / corruption machinery entirely.
    pub fn open_in_memory_for_test() -> rusqlite::Result<Self> {
        let conn = Connection::open_in_memory()?;
        Self::configure(&conn)?;
        let db = Self { conn };
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
        self.conn
            .query_row(
                "SELECT value FROM app_state WHERE key = ?1",
                params![key],
                |row| row.get(0),
            )
            .ok()
    }

    pub fn set_app_state(&self, key: &str, value: &str) -> rusqlite::Result<()> {
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
                 clock_changed, sync_status, created_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, NULL, ?10, NULL, 0, 'OPEN', ?11)",
            params![
                new.id,
                new.user_id,
                new.device_id,
                new.session_type.as_str(),
                new.app_name,
                new.process_name,
                new.window_title,
                new.idle_app_name,
                new.started_at,
                new.last_seen_at,
                created_at,
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
             SET ended_at = ?1, duration_seconds = ?2, clock_changed = ?3, sync_status = 'PENDING'
             WHERE id = ?4",
            params![ended_at_ms, duration_seconds, clock_changed as i64, session_id],
        )?;

        let payload = tx.query_row(
            "SELECT session_type, app_name, process_name, window_title, idle_app_name, started_at
             FROM sessions WHERE id = ?1",
            params![session_id],
            |row| {
                Ok(SyncPayload {
                    id: session_id.to_string(),
                    session_type: row.get(0)?,
                    app_name: row.get(1)?,
                    process_name: row.get(2)?,
                    window_title: row.get(3)?,
                    idle_app_name: row.get(4)?,
                    started_at: row.get::<_, i64>(5)?,
                    ended_at: ended_at_ms,
                    duration_seconds,
                    clock_changed,
                })
            },
        )?;
        let payload_json = serde_json::to_string(&payload).unwrap_or_default();
        let now = chrono::Utc::now().timestamp_millis();
        tx.execute(
            "INSERT INTO sync_queue (entity_type, entity_id, user_id, payload, next_attempt_at, created_at)
             VALUES ('SESSION', ?1, ?2, ?3, ?4, ?5)",
            params![session_id, user_id, payload_json, now, now],
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
            params![window_title, session_id],
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
                    duration_seconds, sync_status
             FROM sessions
             WHERE user_id = ?1 AND started_at >= ?2 AND started_at < ?3
             ORDER BY started_at",
        )?;
        let rows = stmt.query_map(params![user_id, start_ms, end_ms], |row| {
            Ok(SessionRow {
                id: row.get(0)?,
                session_type: SessionType::from_str(&row.get::<_, String>(1)?),
                app_name: row.get(2)?,
                idle_app_name: row.get(3)?,
                started_at: row.get(4)?,
                ended_at: row.get(5)?,
                duration_seconds: row.get(6)?,
                sync_status: row.get(7)?,
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
}
