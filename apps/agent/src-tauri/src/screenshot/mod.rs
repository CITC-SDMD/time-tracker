//! Screenshots (docs Phase 10): take a picture of the main screen on the office's rhythm, keep it in a
//! local queue, and send it once the server is reachable.
//!
//! - `schedule`: when a shot is due (pure, unit-tested).
//! - `capture`: one picture, shrunk and encoded (Windows; a fake in tests).
//! - `upload`: `POST /agent/screenshots` and what each answer means.
//! - this file: `capture_step` and `upload_step` (everything the loop does, testable without Tauri),
//!   and `spawn`, the loop that runs them every 15 seconds.

pub mod capture;
pub mod schedule;
pub mod upload;

use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager};

use crate::api::ApiClient;
use crate::commands::AppState;
use crate::db::ScreenshotRow;
use crate::tracker::clock::Clock;
use crate::tracker::engine::Engine;
use crate::tracker::load_office_settings;
use crate::tracker::state::TrackingState;

use capture::ScreenCapture;
use upload::Outcome;

/// How often the loop looks at the clock.
const TICK: Duration = Duration::from_secs(15);
/// The most pictures kept waiting per person; beyond it the oldest go (a long offline spell must not fill the disk).
pub const MAX_WAITING: usize = 1000;
/// At most this many uploads per tick, spaced out, so the server's 60-a-minute limit is never reached.
const UPLOADS_PER_TICK: usize = 10;
const UPLOAD_GAP: Duration = Duration::from_millis(1100);
/// While offline, look for the internet this often.
const OFFLINE_CHECK_EVERY: u32 = 2; // ticks

fn lock<T>(m: &Mutex<T>) -> std::sync::MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}

fn now_ms() -> i64 {
    chrono::Utc::now().timestamp_millis()
}

fn last_taken_key(user_id: &str) -> String {
    format!("last_screenshot_at:{user_id}")
}

/// What one look at the schedule did.
#[derive(Debug, PartialEq, Eq)]
pub enum Capture {
    /// A picture was taken and queued (its id).
    Taken(String),
    /// Screenshots are off (or the server sent a value that is not allowed).
    Off,
    /// Not tracking, paused or locked: never photographed.
    NotTracking,
    /// The interval has not passed yet.
    NotDue,
    /// The screen could not be captured this time; it is tried again on the next look.
    Failed,
}

/// Takes a screenshot if one is due for `user_id`: only while the state is `Tracking` (active or idle
/// time), only when the office has them on, and never before the interval has passed.
pub fn capture_step<C: Clock>(
    engine: &Arc<Mutex<Engine<C>>>,
    screen: &dyn ScreenCapture,
    dir: &Path,
    user_id: &str,
    now: i64,
) -> Capture {
    let (state, settings, last, cipher) = {
        let e = lock(engine);
        (
            e.state(),
            load_office_settings(e.db()),
            e.db().get_app_state(&last_taken_key(user_id)).and_then(|v| v.parse::<i64>().ok()),
            e.db().cipher().clone(),
        )
    };
    if state != TrackingState::Tracking {
        return Capture::NotTracking;
    }
    if schedule::interval_ms(settings.screenshot_interval_minutes).is_none() {
        return Capture::Off;
    }
    let seed = schedule::seed_for(user_id);
    if !schedule::is_due(now, last, settings.screenshot_interval_minutes, settings.screenshot_random, seed) {
        return Capture::NotDue;
    }

    let shot = match screen.capture() {
        Ok(shot) => shot,
        Err(error) => {
            tracing::warn!(%error, "screenshot could not be taken");
            return Capture::Failed;
        }
    };
    let id = uuid::Uuid::now_v7().to_string();
    let path = dir.join(format!("{id}.jpg"));
    if let Err(error) = std::fs::create_dir_all(dir).and_then(|_| std::fs::write(&path, cipher.seal_bytes(&shot.jpeg))) {
        tracing::warn!(%error, "screenshot could not be saved");
        return Capture::Failed;
    }

    let row = ScreenshotRow {
        id: id.clone(),
        user_id: user_id.to_owned(),
        taken_at: now,
        path: path.to_string_lossy().into_owned(),
        width: i64::from(shot.width),
        height: i64::from(shot.height),
        attempts: 0,
    };
    let e = lock(engine);
    if e.db().add_screenshot(&row, now).is_err() {
        let _ = std::fs::remove_file(&path);
        return Capture::Failed;
    }
    let _ = e.db().set_app_state(&last_taken_key(user_id), &now.to_string());
    if let Ok(dropped) = e.db().trim_screenshots(user_id, MAX_WAITING) {
        for file in dropped {
            let _ = std::fs::remove_file(file);
            tracing::warn!("too many screenshots waiting to be sent: the oldest was dropped");
        }
    }
    Capture::Taken(id)
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct UploadReport {
    pub sent: usize,
    pub dropped: usize,
    /// How the last try ended, when it did not go through (`None` = nothing was left to send or all went well).
    pub stopped_by: Option<Outcome>,
}

/// Sends the waiting pictures of `user_id`, oldest first, at most `max` of them, `gap` apart. Stops at the
/// first problem that would make the next one fail too (no connection, bad token, old app, server trouble).
pub async fn upload_step<C: Clock>(
    engine: &Arc<Mutex<Engine<C>>>,
    api: &ApiClient,
    token: &str,
    user_id: &str,
    max: usize,
    gap: Duration,
) -> UploadReport {
    let mut report = UploadReport::default();
    for attempt in 0..max {
        let next = lock(engine).db().next_screenshot_to_send(user_id, now_ms()).ok().flatten();
        let Some(row) = next else { break };

        let cipher = lock(engine).db().cipher().clone();
        let jpeg = match std::fs::read(&row.path).map(|bytes| cipher.open_bytes(&bytes)) {
            Ok(Some(bytes)) => bytes,
            Ok(None) | Err(_) => {
                // the file is gone (cleaned up by hand) or cannot be read (its key is gone): there is nothing left to send
                let _ = lock(engine).db().delete_screenshot(&row.id);
                report.dropped += 1;
                continue;
            }
        };

        let outcome = upload::upload(api, token, &row, jpeg).await;
        // the lock is held only inside this block, never across an await
        let stop = {
            let e = lock(engine);
            match outcome {
                Outcome::Sent => {
                    let _ = e.db().delete_screenshot(&row.id);
                    let _ = std::fs::remove_file(&row.path);
                    report.sent += 1;
                    None
                }
                Outcome::Dropped => {
                    let _ = e.db().delete_screenshot(&row.id);
                    let _ = std::fs::remove_file(&row.path);
                    report.dropped += 1;
                    None
                }
                Outcome::Retry | Outcome::Offline => {
                    let _ = e.db().schedule_screenshot_retry(&row.id, now_ms());
                    Some(outcome)
                }
                Outcome::NeedsLogin | Outcome::UpgradeRequired => Some(outcome),
            }
        };
        if stop.is_some() {
            report.stopped_by = stop;
            break;
        }
        if attempt + 1 < max {
            tokio::time::sleep(gap).await;
        }
    }
    report
}

/// What the app shows about screenshots (the Today screen and "My screenshots").
#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ScreenshotStatus {
    /// The office has screenshots switched on.
    pub enabled: bool,
    pub interval_minutes: u32,
    pub random: bool,
    /// Pictures taken but not yet sent.
    pub waiting: i64,
    /// When the last one was taken (UTC milliseconds).
    pub last_taken_at: Option<i64>,
}

pub fn status<C: Clock>(engine: &Arc<Mutex<Engine<C>>>, user_id: &str) -> ScreenshotStatus {
    let e = lock(engine);
    let settings = load_office_settings(e.db());
    ScreenshotStatus {
        enabled: schedule::interval_ms(settings.screenshot_interval_minutes).is_some(),
        interval_minutes: settings.screenshot_interval_minutes,
        random: settings.screenshot_random,
        waiting: e.db().pending_screenshot_count(user_id).unwrap_or(0),
        last_taken_at: e.db().get_app_state(&last_taken_key(user_id)).and_then(|v| v.parse().ok()),
    }
}

/// The folder the pictures wait in, next to the local database.
pub fn folder(app: &AppHandle) -> Option<PathBuf> {
    app.path().app_data_dir().ok().map(|dir| dir.join("screenshots"))
}

/// Starts the loop: every 15 seconds look at the schedule, and send what is waiting.
pub fn spawn(app: AppHandle) {
    let Some(dir) = folder(&app) else { return };
    tauri::async_runtime::spawn(async move {
        let screen: Arc<dyn ScreenCapture> = Arc::from(capture::provider());
        let mut offline = false;
        let mut ticks_offline = 0u32;

        loop {
            tokio::time::sleep(TICK).await;
            let state = app.state::<AppState>();
            let Some(user_id) = state.logged_in_user_id() else { continue };

            // 1. take one, if it is time (the screen is captured off the async threads)
            let taken = {
                let engine = state.engine.clone();
                let screen = screen.clone();
                let dir = dir.clone();
                let user = user_id.clone();
                tauri::async_runtime::spawn_blocking(move || capture_step(&engine, screen.as_ref(), &dir, &user, now_ms()))
                    .await
                    .unwrap_or(Capture::Failed)
            };
            if matches!(taken, Capture::Taken(_)) {
                let _ = app.emit("screenshots-changed", ());
            }

            // 2. send what is waiting (needs the token; until the internet is back only look for it now and then)
            let Some(token) = crate::auth::load_token() else { continue };
            if offline {
                ticks_offline += 1;
                if ticks_offline % OFFLINE_CHECK_EVERY != 0 || !state.api.health().await {
                    continue;
                }
                let _ = lock(&state.engine).db().make_screenshots_due(&user_id, now_ms());
            }
            let report = upload_step(&state.engine, &state.api, &token, &user_id, UPLOADS_PER_TICK, UPLOAD_GAP).await;
            offline = report.stopped_by == Some(Outcome::Offline);
            if report.sent + report.dropped > 0 {
                let _ = app.emit("screenshots-changed", ());
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::Db;
    use crate::testutil::{dead_root, MockServer};
    use crate::tracker::clock::FakeClock;
    use crate::tracker::state::{OfficeSettings, TitleMode};
    use crate::tracker::testing::{app, FakeActivityProvider};
    use capture::{CaptureError, Shot};
    use chrono::Utc;

    const USER: &str = "42";

    struct FakeScreen {
        fail: Mutex<bool>,
        taken: Mutex<u32>,
    }

    impl FakeScreen {
        fn new() -> Self {
            Self { fail: Mutex::new(false), taken: Mutex::new(0) }
        }

        fn taken(&self) -> u32 {
            *self.taken.lock().unwrap()
        }
    }

    impl ScreenCapture for FakeScreen {
        fn capture(&self) -> Result<Shot, CaptureError> {
            if *self.fail.lock().unwrap() {
                return Err(CaptureError::Failed("locked".into()));
            }
            *self.taken.lock().unwrap() += 1;
            Ok(Shot { jpeg: vec![0xFF, 0xD8, 0xFF, 0xD9], width: 1280, height: 720 })
        }
    }

    struct Rig {
        engine: Arc<Mutex<Engine<FakeClock>>>,
        screen: FakeScreen,
        dir: tempdir::Dir,
    }

    /// A folder that is removed again when the test ends.
    mod tempdir {
        pub struct Dir(pub std::path::PathBuf);

        impl Dir {
            pub fn new() -> Self {
                let path = std::env::temp_dir().join(format!("shots-{}", uuid::Uuid::now_v7()));
                std::fs::create_dir_all(&path).unwrap();
                Self(path)
            }
        }

        impl Drop for Dir {
            fn drop(&mut self) {
                let _ = std::fs::remove_dir_all(&self.0);
            }
        }
    }

    fn rig(interval_minutes: u32, random: bool) -> Rig {
        let clock = Arc::new(FakeClock::new(Utc::now()));
        let provider = Arc::new(FakeActivityProvider::new((Some(app("Code")), 0)));
        let db = Db::open_in_memory_for_test().unwrap();
        let settings = OfficeSettings { screenshot_interval_minutes: interval_minutes, screenshot_random: random, title_mode: TitleMode::Full, ..OfficeSettings::default() };
        let engine = Engine::new(clock, provider, db, settings, USER.into(), "dev".into());
        // the settings the loop reads are the stored copy, like after a sync
        let stored = format!(r#"{{"idleThresholdSeconds":300,"windowTitleMode":"full","screenshotIntervalMinutes":{interval_minutes},"screenshotRandom":{random}}}"#);
        engine.db().set_app_state("office_settings_json", &stored).unwrap();
        Rig { engine: Arc::new(Mutex::new(engine)), screen: FakeScreen::new(), dir: tempdir::Dir::new() }
    }

    fn step(rig: &Rig, now: i64) -> Capture {
        capture_step(&rig.engine, &rig.screen, &rig.dir.0, USER, now)
    }

    fn files(rig: &Rig) -> usize {
        std::fs::read_dir(&rig.dir.0).unwrap().count()
    }

    const T0: i64 = 1_800_000_000_000;
    const MIN: i64 = 60_000;

    #[test]
    fn nothing_is_taken_unless_tracking_is_on() {
        let rig = rig(10, false);

        assert_eq!(step(&rig, T0), Capture::NotTracking, "stopped");
        lock(&rig.engine).start();
        lock(&rig.engine).pause();
        assert_eq!(step(&rig, T0), Capture::NotTracking, "paused");
        assert_eq!(rig.screen.taken(), 0);
        assert_eq!(files(&rig), 0);
    }

    #[test]
    fn a_locked_or_sleeping_pc_is_never_photographed() {
        let rig = rig(10, false);
        lock(&rig.engine).start();
        lock(&rig.engine).handle_system_event(crate::platform::SystemEvent::Lock);

        assert_eq!(step(&rig, T0), Capture::NotTracking);
        assert_eq!(rig.screen.taken(), 0);
    }

    #[test]
    fn screenshots_off_takes_nothing_even_while_tracking() {
        let rig = rig(0, false);
        lock(&rig.engine).start();

        assert_eq!(step(&rig, T0), Capture::Off);
        assert_eq!(rig.screen.taken(), 0);
    }

    #[test]
    fn a_shot_is_taken_while_tracking_then_every_interval_and_is_queued_with_its_file() {
        let rig = rig(10, false);
        lock(&rig.engine).start();

        let Capture::Taken(id) = step(&rig, T0) else { panic!("the first shot is taken at once") };
        assert_eq!(step(&rig, T0 + 9 * MIN), Capture::NotDue);
        assert!(matches!(step(&rig, T0 + 10 * MIN), Capture::Taken(_)));

        assert_eq!(files(&rig), 2);
        let e = lock(&rig.engine);
        assert_eq!(e.db().pending_screenshot_count(USER).unwrap(), 2);
        let oldest = e.db().next_screenshot_to_send(USER, T0 + 11 * MIN).unwrap().unwrap();
        assert_eq!(oldest.id, id);
        assert_eq!(oldest.taken_at, T0);
        assert_eq!((oldest.width, oldest.height), (1280, 720));
        assert!(std::path::Path::new(&oldest.path).exists());
    }

    #[test]
    fn time_spent_idle_is_photographed_too() {
        let rig = rig(10, false);
        lock(&rig.engine).start();
        // an idle stretch is still the Tracking state: the engine only splits it into idle sessions
        assert_eq!(lock(&rig.engine).state(), TrackingState::Tracking);

        assert!(matches!(step(&rig, T0), Capture::Taken(_)));
    }

    #[test]
    fn a_failed_capture_is_tried_again_on_the_next_look_and_leaves_nothing_behind() {
        let rig = rig(10, false);
        lock(&rig.engine).start();
        *rig.screen.fail.lock().unwrap() = true;

        assert_eq!(step(&rig, T0), Capture::Failed);
        assert_eq!(files(&rig), 0);
        *rig.screen.fail.lock().unwrap() = false;
        assert!(matches!(step(&rig, T0 + 15_000), Capture::Taken(_)));
    }

    #[test]
    fn a_new_interval_from_the_server_applies_at_once() {
        let rig = rig(30, false);
        lock(&rig.engine).start();
        assert!(matches!(step(&rig, T0), Capture::Taken(_)));
        assert_eq!(step(&rig, T0 + 6 * MIN), Capture::NotDue);

        let stored = r#"{"idleThresholdSeconds":300,"windowTitleMode":"full","screenshotIntervalMinutes":5,"screenshotRandom":false}"#;
        lock(&rig.engine).db().set_app_state("office_settings_json", stored).unwrap();

        assert!(matches!(step(&rig, T0 + 6 * MIN), Capture::Taken(_)));
    }

    #[test]
    fn too_many_waiting_pictures_drop_the_oldest_and_delete_their_files() {
        let rig = rig(5, false);
        lock(&rig.engine).start();
        for i in 0..(MAX_WAITING as i64 + 3) {
            assert!(matches!(step(&rig, T0 + i * 5 * MIN), Capture::Taken(_)));
        }

        assert_eq!(lock(&rig.engine).db().pending_screenshot_count(USER).unwrap(), MAX_WAITING as i64);
        assert_eq!(files(&rig), MAX_WAITING);
    }

    #[test]
    fn the_status_reports_the_setting_the_queue_and_the_last_shot() {
        let rig = rig(15, false);
        lock(&rig.engine).start();
        step(&rig, T0);

        let s = status(&rig.engine, USER);

        assert!(s.enabled && !s.random);
        assert_eq!((s.interval_minutes, s.waiting, s.last_taken_at), (15, 1, Some(T0)));
        assert!(!status(&rig.engine, "someone-else").waiting.is_positive(), "each person has their own queue");
    }

    // ---- sending ----------------------------------------------------------------------------

    fn queued(rig: &Rig, count: usize) {
        lock(&rig.engine).start();
        for i in 0..count as i64 {
            assert!(matches!(step(rig, T0 + i * 10 * MIN), Capture::Taken(_)));
        }
    }

    #[tokio::test]
    async fn everything_waiting_is_sent_once_oldest_first_and_the_files_and_rows_go_away() {
        let rig = rig(10, false);
        queued(&rig, 3);
        let server = MockServer::start(vec![(201, "{}".into()), (200, r#"{"status":"duplicate"}"#.into()), (201, "{}".into())]).await;
        let api = ApiClient::new(&server.root, "dev");

        let report = upload_step(&rig.engine, &api, "tok", USER, 10, Duration::from_millis(1)).await;

        assert_eq!(report, UploadReport { sent: 3, dropped: 0, stopped_by: None });
        assert_eq!(server.request_count(), 3);
        assert_eq!(files(&rig), 0, "the local copies are deleted");
        assert_eq!(lock(&rig.engine).db().pending_screenshot_count(USER).unwrap(), 0);
    }

    #[tokio::test]
    async fn at_most_the_given_number_is_sent_per_look() {
        let rig = rig(10, false);
        queued(&rig, 4);
        let server = MockServer::start(vec![(201, "{}".into()), (201, "{}".into())]).await;

        let report = upload_step(&rig.engine, &ApiClient::new(&server.root, "d"), "t", USER, 2, Duration::from_millis(1)).await;

        assert_eq!(report.sent, 2);
        assert_eq!(lock(&rig.engine).db().pending_screenshot_count(USER).unwrap(), 2);
    }

    #[tokio::test]
    async fn offline_keeps_the_pictures_and_stops_at_the_first_failure() {
        let rig = rig(10, false);
        queued(&rig, 3);

        let report = upload_step(&rig.engine, &ApiClient::new(&dead_root().await, "d"), "t", USER, 10, Duration::from_millis(1)).await;

        assert_eq!(report.stopped_by, Some(Outcome::Offline));
        assert_eq!(report.sent, 0);
        assert_eq!(lock(&rig.engine).db().pending_screenshot_count(USER).unwrap(), 3);
        assert_eq!(files(&rig), 3);
        // it backs off, and is due again when the internet is back
        lock(&rig.engine).db().make_screenshots_due(USER, now_ms()).unwrap();
        assert!(lock(&rig.engine).db().next_screenshot_to_send(USER, now_ms()).unwrap().is_some());
    }

    #[tokio::test]
    async fn a_bad_token_keeps_all_the_data_for_the_next_login() {
        let rig = rig(10, false);
        queued(&rig, 2);
        let server = MockServer::start(vec![(401, r#"{"error":{"code":"UNAUTHENTICATED","message":"x"}}"#.into())]).await;

        let report = upload_step(&rig.engine, &ApiClient::new(&server.root, "d"), "old", USER, 10, Duration::from_millis(1)).await;

        assert_eq!(report.stopped_by, Some(Outcome::NeedsLogin));
        assert_eq!(server.request_count(), 1, "it does not keep trying with a token that does not work");
        assert_eq!(lock(&rig.engine).db().pending_screenshot_count(USER).unwrap(), 2);
    }

    #[tokio::test]
    async fn a_server_that_refuses_for_good_drops_the_picture_and_carries_on() {
        let rig = rig(10, false);
        queued(&rig, 2);
        let server = MockServer::start(vec![
            (409, r#"{"error":{"code":"SCREENSHOTS_DISABLED","message":"off"}}"#.into()),
            (409, r#"{"error":{"code":"SCREENSHOTS_DISABLED","message":"off"}}"#.into()),
        ])
        .await;

        let report = upload_step(&rig.engine, &ApiClient::new(&server.root, "d"), "t", USER, 10, Duration::from_millis(1)).await;

        assert_eq!(report, UploadReport { sent: 0, dropped: 2, stopped_by: None });
        assert_eq!(files(&rig), 0);
    }

    #[tokio::test]
    async fn a_missing_file_is_forgotten_instead_of_blocking_the_queue() {
        let rig = rig(10, false);
        queued(&rig, 2);
        let first = lock(&rig.engine).db().next_screenshot_to_send(USER, now_ms()).unwrap().unwrap();
        std::fs::remove_file(&first.path).unwrap();
        let server = MockServer::start(vec![(201, "{}".into())]).await;

        let report = upload_step(&rig.engine, &ApiClient::new(&server.root, "d"), "t", USER, 10, Duration::from_millis(1)).await;

        assert_eq!((report.sent, report.dropped), (1, 1));
    }

    #[tokio::test]
    async fn another_persons_pictures_are_never_sent_with_this_persons_token() {
        let rig = rig(10, false);
        queued(&rig, 2);
        let server = MockServer::start(vec![]).await;

        let report = upload_step(&rig.engine, &ApiClient::new(&server.root, "d"), "t", "7", 10, Duration::from_millis(1)).await;

        assert_eq!(report, UploadReport::default());
        assert_eq!(server.request_count(), 0);
        assert_eq!(lock(&rig.engine).db().pending_screenshot_count(USER).unwrap(), 2, "kept for their own login");
    }
}
