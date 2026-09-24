//! The sync worker (docs §11.1). `sync_cycle` is the whole send algorithm and knows nothing
//! about Tauri, so it is unit-tested against a mock server; `spawn` wraps it in the
//! scheduling loop (every 2 minutes, on demand, and the offline `/health` polling).

use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager};
use tokio::sync::Notify;

use crate::api::{ApiClient, ApiError};
use crate::commands::{end_session, AppState};
use crate::db::QueueRow;
use crate::sync::client::{iso_from, post_sync, wire_session, SyncCommands, SyncRequest, SyncResponse, SyncSettings, SyncStatusDto};
use crate::tracker::clock::Clock;
use crate::tracker::engine::Engine;
use crate::tracker::load_office_settings;

pub const BATCH_SIZE: usize = 100;
pub const MAX_BATCHES_PER_CYCLE: usize = 300;
/// Gap between full batches: the server allows 30 requests a minute (docs §10.3), and 2.2s
/// apart is 27 a minute, so a big backlog goes out non-stop without ever being throttled.
const BATCH_PACE: Duration = Duration::from_millis(2200);
const NORMAL_INTERVAL: Duration = Duration::from_secs(120);
const OFFLINE_HEALTH_INTERVAL: Duration = Duration::from_secs(30);

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Outcome {
    Ok,
    /// No connection: rows are backed off, and the loop switches to `/health` polling.
    Offline,
    /// 401: the stored token is no longer valid. Data is kept.
    NeedsLogin,
    /// 426: this app is too old. Tracking continues locally.
    UpgradeRequired,
    /// 429, 5xx or anything else temporary.
    RetryLater(String),
}

#[derive(Debug, Clone)]
pub struct CycleReport {
    pub outcome: Outcome,
    pub sent: usize,
    pub stop_tracking: bool,
    pub stop_reason: Option<String>,
    pub sign_out: bool,
    /// The state that was reported to the server in the last request.
    pub reported_state: &'static str,
}

fn lock<T>(m: &Mutex<T>) -> std::sync::MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}

fn now_ms() -> i64 {
    chrono::Utc::now().timestamp_millis()
}

fn computer_name() -> Option<String> {
    std::env::var("COMPUTERNAME").ok().filter(|n| !n.is_empty())
}

/// Sends everything that is due for `user_id`, batch by batch without stopping until the
/// queue is empty, always including the current status. `pace` is the gap left between
/// full batches to stay under the server's rate limit. Sessions of any other user are
/// left untouched.
pub async fn sync_cycle<C>(
    engine: &Arc<Mutex<Engine<C>>>,
    api: &ApiClient,
    token: &str,
    user_id: &str,
    pace: Duration,
) -> CycleReport
where
    C: Clock + Send + Sync + 'static,
{
    let mut report = CycleReport {
        outcome: Outcome::Ok,
        sent: 0,
        stop_tracking: false,
        stop_reason: None,
        sign_out: false,
        reported_state: "NOT_TRACKING",
    };

    for batch in 0..MAX_BATCHES_PER_CYCLE {
        let (rows, snapshot, db_reset) = {
            let e = lock(engine);
            let rows = e
                .db()
                .fetch_ready_queue(user_id, BATCH_SIZE, now_ms())
                .unwrap_or_default();
            let db_reset = e.db().get_app_state("db_reset_pending").as_deref() == Some("1");
            (rows, e.status_snapshot(), db_reset)
        };
        if batch > 0 && rows.is_empty() {
            break;
        }

        let mut sendable: Vec<&QueueRow> = Vec::new();
        let mut sessions = Vec::new();
        for row in &rows {
            match wire_session(&row.payload) {
                Some(session) => {
                    sessions.push(session);
                    sendable.push(row);
                }
                None => {
                    let e = lock(engine);
                    let _ = e.db().mark_rejected(&row.entity_id, "UNREADABLE");
                }
            }
        }

        report.reported_state = snapshot.state;
        let request = SyncRequest {
            client_time: iso_from(chrono::Utc::now()),
            computer_name: computer_name(),
            db_reset,
            status: SyncStatusDto {
                state: snapshot.state,
                current_app: snapshot.current_app,
                idle_app_name: snapshot.idle_app_name,
                since: snapshot.since.map(iso_from),
                tracking_started_at: snapshot.tracking_started_at.map(iso_from),
            },
            sessions,
        };

        match post_sync(api, token, &request).await {
            Ok(response) => {
                report.sent += sendable.len();
                let commands = apply_response(engine, &sendable, response, db_reset);
                report.stop_tracking |= commands.stop_tracking;
                report.sign_out |= commands.sign_out;
                if commands.stop_reason.is_some() {
                    report.stop_reason = commands.stop_reason;
                }
                if rows.len() < BATCH_SIZE {
                    break;
                }
                tokio::time::sleep(pace).await;
            }
            Err(err) => {
                report.outcome = match &err {
                    ApiError::Unauthorized => Outcome::NeedsLogin,
                    ApiError::UpgradeRequired => Outcome::UpgradeRequired,
                    ApiError::Offline => Outcome::Offline,
                    other => Outcome::RetryLater(other.code()),
                };
                // 401 and 426 leave the rows alone: nothing is wrong with the data, and the
                // attempt count should not creep up while the person logs in or updates.
                if !matches!(report.outcome, Outcome::NeedsLogin | Outcome::UpgradeRequired) {
                    let e = lock(engine);
                    for row in &sendable {
                        let _ = e.db().schedule_retry(&row.entity_id, &err.code(), now_ms());
                    }
                    let _ = e.db().set_app_state("last_sync_error", &err.code());
                }
                break;
            }
        }
    }

    report
}

/// Records what the server decided, applies its settings, and carries out its commands.
fn apply_response<C>(
    engine: &Arc<Mutex<Engine<C>>>,
    sent: &[&QueueRow],
    response: SyncResponse,
    db_reset_was_sent: bool,
) -> SyncCommands
where
    C: Clock + Send + Sync + 'static,
{
    let done: HashSet<&str> = response
        .accepted
        .iter()
        .chain(response.duplicates.iter())
        .map(String::as_str)
        .collect();
    let rejected: HashMap<&str, &str> = response
        .rejected
        .iter()
        .map(|r| (r.id.as_str(), r.reason.as_str()))
        .collect();

    let mut e = lock(engine);
    for row in sent {
        let id = row.entity_id.as_str();
        let result = if done.contains(id) {
            e.db().mark_synced(id)
        } else if let Some(reason) = rejected.get(id) {
            tracing::warn!(session = id, reason, "server rejected a session");
            e.db().mark_rejected(id, reason)
        } else {
            e.db().schedule_retry(id, "NOT_ACKNOWLEDGED", now_ms())
        };
        if let Err(err) = result {
            tracing::error!(?err, "could not record the sync result for a session");
        }
    }

    if db_reset_was_sent {
        let _ = e.db().set_app_state("db_reset_pending", "0");
    }
    let _ = e.db().set_app_state("last_sync_at", &now_ms().to_string());
    let _ = e.db().set_app_state("last_sync_error", "");
    store_settings(&mut e, &response.settings);

    if response.commands.stop_tracking {
        e.stop();
    }
    response.commands
}

fn store_settings<C: Clock>(engine: &mut Engine<C>, settings: &SyncSettings) {
    let mut stored: serde_json::Value = engine
        .db()
        .get_app_state("office_settings_json")
        .and_then(|json| serde_json::from_str(&json).ok())
        .unwrap_or_else(|| serde_json::json!({}));
    stored["idleThresholdSeconds"] = settings.idle_threshold_seconds.into();
    stored["windowTitleMode"] = settings.window_title_mode.clone().into();
    let _ = engine.db().set_app_state("office_settings_json", &stored.to_string());
    let loaded = load_office_settings(engine.db());
    engine.apply_settings(loaded);
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncStatus {
    pub last_sync_at: Option<i64>,
    pub last_error: Option<String>,
    pub online: bool,
    pub needs_login: bool,
    pub upgrade_required: bool,
}

impl Default for SyncStatus {
    fn default() -> Self {
        Self {
            last_sync_at: None,
            last_error: None,
            online: true,
            needs_login: false,
            upgrade_required: false,
        }
    }
}

/// Shared between the worker loop and the commands (`stop_tracking`, `login`, `logout`).
#[derive(Clone, Default)]
pub struct SyncHandle {
    notify: Arc<Notify>,
    /// Held for the length of a cycle so the loop and a logout flush never overlap.
    cycle: Arc<tokio::sync::Mutex<()>>,
    status: Arc<Mutex<SyncStatus>>,
}

impl SyncHandle {
    /// Ask the worker to sync now (after Stop, after login).
    pub fn trigger(&self) {
        self.notify.notify_one();
    }

    pub fn status(&self) -> SyncStatus {
        lock(&self.status).clone()
    }

    fn update(&self, f: impl FnOnce(&mut SyncStatus)) -> SyncStatus {
        let mut status = lock(&self.status);
        f(&mut status);
        status.clone()
    }
}

/// Logout flush (docs §6.3): make everything due, then try once within `limit`. Returns
/// `None` if it timed out.
pub async fn flush<C>(
    handle: &SyncHandle,
    engine: &Arc<Mutex<Engine<C>>>,
    api: &ApiClient,
    token: &str,
    user_id: &str,
    limit: Duration,
) -> Option<CycleReport>
where
    C: Clock + Send + Sync + 'static,
{
    let attempt = async {
        let _cycle = handle.cycle.lock().await;
        let _ = lock(engine).db().make_pending_due(user_id, now_ms());
        sync_cycle(engine, api, token, user_id, BATCH_PACE).await
    };
    tokio::time::timeout(limit, attempt).await.ok()
}

/// Starts the scheduling loop: sync every 2 minutes while logged in, right away when
/// triggered, and while offline poll `/health` every 30 seconds so the queue is sent
/// the moment the internet returns.
pub fn spawn(app: AppHandle, handle: SyncHandle) {
    tauri::async_runtime::spawn(async move {
        let mut offline = false;
        let mut last_reported_state = "";

        loop {
            let wait = if offline { OFFLINE_HEALTH_INTERVAL } else { NORMAL_INTERVAL };
            tokio::select! {
                _ = handle.notify.notified() => {}
                _ = tokio::time::sleep(wait) => {}
            }

            let state = app.state::<AppState>();
            let Some(user_id) = state.logged_in_user_id() else { continue };
            let Some(token) = crate::auth::load_token() else { continue };

            let _cycle = handle.cycle.lock().await;
            if offline {
                if !state.api.health().await {
                    continue;
                }
                let _ = lock(&state.engine).db().make_pending_due(&user_id, now_ms());
                offline = false;
            }

            // Nothing to say while stopped and already reported as stopped.
            let (snapshot_state, pending) = {
                let e = lock(&state.engine);
                (e.status_snapshot().state, e.db().pending_count(&user_id).unwrap_or(0))
            };
            // (Not while a warning is showing: this pass is what clears it, e.g. right after logging in again.)
            let warning_showing = {
                let s = handle.status();
                s.needs_login || s.upgrade_required
            };
            if snapshot_state == "NOT_TRACKING"
                && pending == 0
                && last_reported_state == "NOT_TRACKING"
                && !warning_showing
            {
                continue;
            }

            let report = sync_cycle(&state.engine, &state.api, &token, &user_id, BATCH_PACE).await;
            if report.outcome == Outcome::Ok {
                last_reported_state = report.reported_state;
            }
            offline = report.outcome == Outcome::Offline;

            let status = handle.update(|s| {
                s.online = report.outcome != Outcome::Offline;
                s.needs_login = report.outcome == Outcome::NeedsLogin;
                s.upgrade_required = report.outcome == Outcome::UpgradeRequired;
                match &report.outcome {
                    Outcome::Ok => {
                        s.last_sync_at = Some(now_ms());
                        s.last_error = None;
                    }
                    Outcome::RetryLater(code) => s.last_error = Some(code.clone()),
                    _ => {}
                }
            });
            let _ = app.emit("sync-status-changed", &status);

            match report.outcome {
                Outcome::NeedsLogin => {
                    end_session(&state);
                    crate::notify::notice(&app, "Please log in again.");
                }
                Outcome::UpgradeRequired => {
                    crate::notify::notice(&app, "Please update the app. Tracking keeps working, and your data is sent after the update.");
                }
                _ => {}
            }
            if report.stop_tracking {
                let message = match report.stop_reason.as_deref() {
                    Some("STARTED_ON_OTHER_PC") => "Tracking was started on another computer.",
                    _ => "Tracking was stopped by the server.",
                };
                crate::notify::notice(&app, message);
                let _ = app.emit("tracking-stopped", ());
            }
            if report.sign_out {
                end_session(&state);
                crate::notify::notice(&app, "Your account was deactivated. You have been signed out.");
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::testutil::{dead_root, MockServer};
    use crate::tracker::clock::FakeClock;
    use crate::tracker::state::{OfficeSettings, TitleMode};
    use crate::tracker::testing::FakeActivityProvider;
    use crate::db::{Db, NewSession, SessionType};
    use chrono::Utc;

    const USER: &str = "1";

    fn engine() -> Arc<Mutex<Engine<FakeClock>>> {
        engine_with_controls(300, None).0
    }

    /// An engine plus handles to move its clock and change what the "PC" is doing.
    fn engine_with_controls(
        idle_limit_seconds: u64,
        foreground: Option<crate::platform::ForegroundApp>,
    ) -> (Arc<Mutex<Engine<FakeClock>>>, Arc<FakeClock>, Arc<FakeActivityProvider>) {
        let clock = Arc::new(FakeClock::new(Utc::now()));
        let provider = Arc::new(FakeActivityProvider::new((foreground, 0)));
        let db = Db::open_in_memory_for_test().unwrap();
        let settings = OfficeSettings { idle_limit_seconds, title_mode: TitleMode::Full };
        let engine = Engine::new(clock.clone(), provider.clone(), db, settings, USER.into(), "dev".into());
        (Arc::new(Mutex::new(engine)), clock, provider)
    }

    /// Queues `count` closed 5-second sessions for `user`, returning their ids in send order.
    fn queue(engine: &Arc<Mutex<Engine<FakeClock>>>, user: &str, count: usize) -> Vec<String> {
        let e = lock(engine);
        (0..count)
            .map(|i| {
                let id = uuid::Uuid::now_v7().to_string();
                e.db()
                    .open_session(&NewSession {
                        id: id.clone(),
                        user_id: user.into(),
                        device_id: "dev".into(),
                        session_type: SessionType::Application,
                        app_name: Some("Code".into()),
                        process_name: Some("Code.exe".into()),
                        window_title: None,
                        idle_app_name: None,
                        started_at: 1_000_000 + i as i64 * 10_000,
                        last_seen_at: 1_000_000,
                    })
                    .unwrap();
                e.db().close_session(&id, 1_005_000 + i as i64 * 10_000, false).unwrap();
                id
            })
            .collect()
    }

    fn ok_body(accepted: &[String], duplicates: &[String], rejected: &[(&str, &str)], commands: &str) -> String {
        let rejected: Vec<_> = rejected.iter().map(|(id, r)| serde_json::json!({"id": id, "reason": r})).collect();
        format!(
            r#"{{"accepted":{},"duplicates":{},"rejected":{},"serverTime":"2026-09-25T10:00:00.000Z","commands":{commands},"settings":{{"idleThresholdSeconds":420,"windowTitleMode":"APP_ONLY"}}}}"#,
            serde_json::to_string(accepted).unwrap(),
            serde_json::to_string(duplicates).unwrap(),
            serde_json::to_string(&rejected).unwrap(),
        )
    }

    const NO_COMMANDS: &str = r#"{"stopTracking":false,"stopReason":null,"signOut":false}"#;

    fn error(code: &str) -> String {
        format!(r#"{{"error":{{"code":"{code}","message":"x"}}}}"#)
    }

    fn pending(engine: &Arc<Mutex<Engine<FakeClock>>>) -> i64 {
        lock(engine).db().pending_count(USER).unwrap()
    }

    fn body_of(request: &str) -> serde_json::Value {
        serde_json::from_str(request.split("\r\n\r\n").nth(1).unwrap()).unwrap()
    }

    #[tokio::test]
    async fn a_batch_is_sent_with_iso_times_status_and_marked_synced() {
        let engine = engine();
        let ids = queue(&engine, USER, 3);
        let server = MockServer::start(vec![(200, ok_body(&ids, &[], &[], NO_COMMANDS))]).await;
        let api = ApiClient::new(&server.root, "dev");

        let report = sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert_eq!(report.outcome, Outcome::Ok);
        assert_eq!(report.sent, 3);
        assert_eq!(pending(&engine), 0);
        let raw = server.request(0);
        assert!(raw.to_lowercase().contains("authorization: bearer tok"));
        let body = body_of(&raw);
        assert_eq!(body["status"]["state"], "NOT_TRACKING");
        assert_eq!(body["dbReset"], false);
        assert_eq!(body["sessions"].as_array().unwrap().len(), 3);
        assert_eq!(body["sessions"][0]["id"], ids[0].as_str());
        assert_eq!(body["sessions"][0]["startedAt"], "1970-01-01T00:16:40.000Z");
        let rows = lock(&engine).db().sessions_for_range(USER, 0, i64::MAX).unwrap();
        assert!(rows.iter().all(|r| r.sync_status == "SYNCED"));
    }

    #[tokio::test]
    async fn duplicates_count_as_sent_and_rejected_sessions_are_marked_rejected() {
        let engine = engine();
        let ids = queue(&engine, USER, 3);
        let body = ok_body(&ids[..1], &ids[1..2], &[(&ids[2], "TOO_OLD")], NO_COMMANDS);
        let server = MockServer::start(vec![(200, body)]).await;
        let api = ApiClient::new(&server.root, "dev");

        sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert_eq!(pending(&engine), 0);
        let rows = lock(&engine).db().sessions_for_range(USER, 0, i64::MAX).unwrap();
        let status = |id: &str| rows.iter().find(|r| r.id == id).unwrap().sync_status.clone();
        assert_eq!(status(&ids[0]), "SYNCED");
        assert_eq!(status(&ids[1]), "SYNCED");
        assert_eq!(status(&ids[2]), "REJECTED");
    }

    #[tokio::test]
    async fn offline_keeps_the_data_and_backs_off() {
        let engine = engine();
        queue(&engine, USER, 2);
        let api = ApiClient::new(&dead_root().await, "dev");

        let report = sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert_eq!(report.outcome, Outcome::Offline);
        assert_eq!(pending(&engine), 2);
        // Backed off: not due again right away...
        assert!(lock(&engine).db().fetch_ready_queue(USER, 100, now_ms()).unwrap().is_empty());
        // ...until the internet is detected again.
        lock(&engine).db().make_pending_due(USER, now_ms()).unwrap();
        assert_eq!(lock(&engine).db().fetch_ready_queue(USER, 100, now_ms()).unwrap().len(), 2);
    }

    #[tokio::test]
    async fn a_401_keeps_the_data_and_asks_for_login() {
        let engine = engine();
        queue(&engine, USER, 2);
        let server = MockServer::start(vec![(401, error("UNAUTHENTICATED"))]).await;
        let api = ApiClient::new(&server.root, "dev");

        let report = sync_cycle(&engine, &api, "old", USER, Duration::ZERO).await;

        assert_eq!(report.outcome, Outcome::NeedsLogin);
        assert_eq!(pending(&engine), 2);
        assert_eq!(lock(&engine).db().fetch_ready_queue(USER, 100, now_ms()).unwrap().len(), 2);
    }

    #[tokio::test]
    async fn a_426_keeps_the_data_and_asks_for_an_update() {
        let engine = engine();
        queue(&engine, USER, 2);
        let server = MockServer::start(vec![(426, error("UPGRADE_REQUIRED"))]).await;
        let api = ApiClient::new(&server.root, "dev");

        let report = sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert_eq!(report.outcome, Outcome::UpgradeRequired);
        assert_eq!(pending(&engine), 2);
    }

    #[tokio::test]
    async fn server_errors_and_rate_limits_retry_later_without_losing_anything() {
        for (status, body, code) in [(503, "oops".to_owned(), "SERVER_ERROR"), (429, error("TOO_MANY_REQUESTS"), "RATE_LIMITED")] {
            let engine = engine();
            queue(&engine, USER, 2);
            let server = MockServer::start(vec![(status, body)]).await;
            let api = ApiClient::new(&server.root, "dev");

            let report = sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

            assert_eq!(report.outcome, Outcome::RetryLater(code.into()));
            assert_eq!(pending(&engine), 2);
            assert!(lock(&engine).db().fetch_ready_queue(USER, 100, now_ms()).unwrap().is_empty());
        }
    }

    #[tokio::test]
    async fn another_users_data_is_never_sent() {
        let engine = engine();
        let mine = queue(&engine, USER, 1);
        let theirs = queue(&engine, "2", 2);
        let server = MockServer::start(vec![(200, ok_body(&mine, &[], &[], NO_COMMANDS))]).await;
        let api = ApiClient::new(&server.root, "dev");

        sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        let body = body_of(&server.request(0));
        assert_eq!(body["sessions"].as_array().unwrap().len(), 1);
        assert_eq!(server.request_count(), 1);
        assert_eq!(lock(&engine).db().pending_count("2").unwrap(), 2);
        assert!(theirs.iter().all(|id| !server.request(0).contains(id.as_str())));
    }

    #[tokio::test]
    async fn a_long_backlog_goes_out_in_one_continuous_run_of_batches_of_100() {
        let engine = engine();
        let ids = queue(&engine, USER, 3000);
        let responses = ids
            .chunks(BATCH_SIZE)
            .map(|chunk| (200, ok_body(chunk, &[], &[], NO_COMMANDS)))
            .collect();
        let server = MockServer::start(responses).await;
        let api = ApiClient::new(&server.root, "dev");

        let report = sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert_eq!(report.outcome, Outcome::Ok);
        assert_eq!(server.request_count(), 30);
        assert_eq!(report.sent, 3000);
        assert_eq!(pending(&engine), 0);
    }

    #[tokio::test]
    async fn full_batches_are_spaced_by_the_pace_and_the_last_one_is_not_delayed() {
        let engine = engine();
        let ids = queue(&engine, USER, 250);
        let server = MockServer::start(vec![
            (200, ok_body(&ids[..100], &[], &[], NO_COMMANDS)),
            (200, ok_body(&ids[100..200], &[], &[], NO_COMMANDS)),
            (200, ok_body(&ids[200..], &[], &[], NO_COMMANDS)),
        ])
        .await;
        let api = ApiClient::new(&server.root, "dev");

        let started = std::time::Instant::now();
        sync_cycle(&engine, &api, "tok", USER, Duration::from_millis(300)).await;
        let elapsed = started.elapsed();

        // Two full batches, so two gaps; the final partial batch adds none.
        assert!(elapsed >= Duration::from_millis(600), "took {elapsed:?}");
        assert!(elapsed < Duration::from_millis(900), "took {elapsed:?}");
        assert_eq!(pending(&engine), 0);
    }

    #[tokio::test]
    async fn the_reported_status_follows_active_idle_paused_and_stopped() {
        use crate::tracker::testing::app;
        let (engine, clock, provider) = engine_with_controls(5, Some(app("VSCode")));
        let server = MockServer::start((0..4).map(|_| (200, ok_body(&[], &[], &[], NO_COMMANDS))).collect()).await;
        let api = ApiClient::new(&server.root, "dev");
        let report = |i: usize| body_of(&server.request(i))["status"].clone();

        lock(&engine).start();
        for _ in 0..3 {
            clock.advance(Duration::from_secs(2));
            lock(&engine).tick();
        }
        sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;
        assert_eq!(report(0)["state"], "ACTIVE");
        assert_eq!(report(0)["currentApp"], "VSCode");
        assert!(report(0)["trackingStartedAt"].is_string());

        // No input for longer than the idle limit: the next tick moves to IDLE, in VSCode.
        // (The first sync applied the server's limit of 420s from the mock reply.)
        provider.set(Some(app("VSCode")), 500);
        clock.advance(Duration::from_secs(2));
        lock(&engine).tick();
        sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;
        assert_eq!(report(1)["state"], "IDLE");
        assert_eq!(report(1)["idleAppName"], "VSCode");

        provider.set(Some(app("VSCode")), 0);
        clock.advance(Duration::from_secs(2));
        lock(&engine).tick();
        lock(&engine).pause();
        sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;
        assert_eq!(report(2)["state"], "PAUSED");

        lock(&engine).resume();
        clock.advance(Duration::from_secs(3));
        lock(&engine).stop();
        sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;
        assert_eq!(report(3)["state"], "NOT_TRACKING");
        assert!(report(3)["trackingStartedAt"].is_null());
    }

    #[tokio::test]
    async fn a_stop_order_from_the_server_stops_a_tracking_app() {
        use crate::tracker::state::TrackingState;
        use crate::tracker::testing::app;
        let (engine, clock, _provider) = engine_with_controls(300, Some(app("VSCode")));
        lock(&engine).start();
        clock.advance(Duration::from_secs(4));
        let commands = r#"{"stopTracking":true,"stopReason":"STARTED_ON_OTHER_PC","signOut":false}"#;
        let server = MockServer::start(vec![(200, ok_body(&[], &[], &[], commands))]).await;
        let api = ApiClient::new(&server.root, "dev");

        let report = sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert!(report.stop_tracking);
        assert_eq!(report.stop_reason.as_deref(), Some("STARTED_ON_OTHER_PC"));
        assert_eq!(lock(&engine).state(), TrackingState::NotTracking);
        // What was recorded before the order is kept and still gets sent.
        assert_eq!(pending(&engine), 1);
    }

    #[tokio::test]
    async fn logout_flush_sends_everything_waiting_even_rows_backed_off_after_a_failure() {
        let engine = engine();
        let ids = queue(&engine, USER, 250);
        // An earlier failed attempt left these rows backed off for a while.
        for id in &ids {
            lock(&engine).db().schedule_retry(id, "SERVER_ERROR", now_ms()).unwrap();
        }
        assert_eq!(pending(&engine), 250);
        let server = MockServer::start(vec![
            (200, ok_body(&ids[..100], &[], &[], NO_COMMANDS)),
            (200, ok_body(&ids[100..200], &[], &[], NO_COMMANDS)),
            (200, ok_body(&ids[200..], &[], &[], NO_COMMANDS)),
        ])
        .await;
        let api = ApiClient::new(&server.root, "dev");
        let handle = SyncHandle::default();

        let report = flush(&handle, &engine, &api, "tok", USER, Duration::from_secs(30))
            .await
            .expect("finishes well inside the 30 second limit");

        assert_eq!(report.outcome, Outcome::Ok);
        assert_eq!(pending(&engine), 0);
    }

    #[tokio::test]
    async fn logout_flush_offline_returns_quickly_and_keeps_the_data() {
        let engine = engine();
        queue(&engine, USER, 5);
        let api = ApiClient::new(&dead_root().await, "dev");
        let handle = SyncHandle::default();

        let started = std::time::Instant::now();
        let report = flush(&handle, &engine, &api, "tok", USER, Duration::from_secs(30)).await.unwrap();

        assert_eq!(report.outcome, Outcome::Offline);
        assert!(started.elapsed() < Duration::from_secs(5));
        assert_eq!(pending(&engine), 5);
    }

    #[tokio::test]
    async fn the_last_partial_batch_ends_the_cycle() {
        let engine = engine();
        let ids = queue(&engine, USER, 150);
        let server = MockServer::start(vec![
            (200, ok_body(&ids[..100], &[], &[], NO_COMMANDS)),
            (200, ok_body(&ids[100..], &[], &[], NO_COMMANDS)),
        ])
        .await;
        let api = ApiClient::new(&server.root, "dev");

        sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert_eq!(server.request_count(), 2);
        assert_eq!(pending(&engine), 0);
    }

    #[tokio::test]
    async fn server_commands_and_settings_are_applied() {
        let engine = engine();
        lock(&engine).db().set_app_state("db_reset_pending", "1").unwrap();
        let commands = r#"{"stopTracking":true,"stopReason":"STARTED_ON_OTHER_PC","signOut":true}"#;
        let server = MockServer::start(vec![(200, ok_body(&[], &[], &[], commands))]).await;
        let api = ApiClient::new(&server.root, "dev");

        let report = sync_cycle(&engine, &api, "tok", USER, Duration::ZERO).await;

        assert!(report.stop_tracking);
        assert!(report.sign_out);
        assert_eq!(report.stop_reason.as_deref(), Some("STARTED_ON_OTHER_PC"));
        assert_eq!(body_of(&server.request(0))["dbReset"], true);
        let e = lock(&engine);
        assert_eq!(e.db().get_app_state("db_reset_pending").as_deref(), Some("0"));
        assert!(e.db().get_app_state("office_settings_json").unwrap().contains("APP_ONLY"));
        assert!(e.db().get_app_state("last_sync_at").is_some());
    }
}
