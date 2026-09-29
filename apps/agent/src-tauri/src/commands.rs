//! Commands the Vue side can call with `invoke(...)`.

use std::sync::{Arc, Mutex};

use chrono::TimeZone;
use serde::Serialize;
use tauri::{AppHandle, Manager, State};
use tauri_plugin_autostart::ManagerExt;
use tracing_appender::non_blocking::WorkerGuard;

use crate::view::{build_app_list, build_task_list, build_timeline, AppTimeDto, SegmentDto, TaskTimeDto};
use crate::api::{ApiClient, ApiError, MeDto};
use crate::sync::worker::{flush, SyncHandle};
use crate::tracker::clock::SystemClock;
use crate::tracker::engine::Engine;
use crate::tracker::load_office_settings;
use crate::tracker::state::{SessionKind, TrackingState};
use crate::{auth, db};

pub struct AppState {
    pub engine: Arc<Mutex<Engine<SystemClock>>>,
    pub api: ApiClient,
    pub sync: SyncHandle,
    /// Who is logged in on this PC, or `None`. The token itself stays in Credential Manager.
    pub session: Mutex<Option<MeDto>>,
    /// Kept alive for the app's lifetime -- dropping it stops tracing-appender's flush thread.
    pub _log_guard: WorkerGuard,
}

impl AppState {
    /// The id sync should send for, or `None` while logged out.
    pub fn logged_in_user_id(&self) -> Option<String> {
        self.session.lock().ok()?.as_ref().map(|me| me.id.clone())
    }
}

/// Forgets the login on this PC: token gone, cached `Me` cleared. Queued data stays keyed
/// to its user and is sent when that user logs in again.
pub fn end_session(state: &AppState) {
    auth::clear_token();
    let engine = state.engine.lock().unwrap_or_else(|e| e.into_inner());
    let _ = engine.db().set_app_state("me_json", "");
    drop(engine);
    *state.session.lock().unwrap_or_else(|e| e.into_inner()) = None;
}

/// Rebuilds the logged-in state at startup from Credential Manager plus the cached `Me`.
/// Needs no network, so a PC that starts offline keeps tracking.
pub fn restore_session(db: &db::Db) -> Option<MeDto> {
    auth::load_token()?;
    let me: MeDto = serde_json::from_str(&db.get_app_state("me_json")?).ok()?;
    let user_id = db.get_app_state("current_user_id")?;
    (me.id == user_id && me.status == "active").then_some(me)
}

/// Whether the person may start tracking: logged in, and consent given for the current version.
pub fn can_track(me: &Option<MeDto>) -> bool {
    matches!(me, Some(me) if !me.consent_required)
}

fn require_ready(state: &AppState) -> Result<(), String> {
    match &*state.session.lock().map_err(|_| "session lock poisoned")? {
        None => Err("NOT_LOGGED_IN".into()),
        Some(me) if me.consent_required => Err("CONSENT_REQUIRED".into()),
        Some(_) => Ok(()),
    }
}

/// Saves the latest `Me` (for offline restarts) and the office settings the engine uses.
fn remember_me(engine: &mut Engine<SystemClock>, me: &MeDto) {
    if let Ok(json) = serde_json::to_string(me) {
        let _ = engine.db().set_app_state("me_json", &json);
    }
    if let Ok(json) = serde_json::to_string(&me.office_settings) {
        let _ = engine.db().set_app_state("office_settings_json", &json);
    }
    let settings = load_office_settings(engine.db());
    engine.apply_settings(settings);
}

fn api_error_string(err: ApiError) -> String {
    err.code()
}

/// Stops tracking, then tries to send everything within `max`. Returns the user id whose
/// data was sent. Used by log out and by quitting from the tray.
pub async fn stop_and_flush(state: &AppState, max: std::time::Duration) -> Result<String, String> {
    let user_id = {
        let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
        engine.stop();
        engine.user_id().to_owned()
    };
    if let Some(token) = auth::load_token() {
        let _ = flush(&state.sync, &state.engine, &state.api, &token, &user_id, max).await;
    }
    Ok(user_id)
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LogoutDto {
    pub synced: bool,
    pub pending_count: i64,
}

#[tauri::command]
pub async fn login(
    state: State<'_, AppState>,
    email: String,
    password: String,
) -> Result<MeDto, String> {
    let (token, me) = state.api.login(&email, &password).await.map_err(api_error_string)?;
    if me.status != "active" {
        return Err("DEACTIVATED".into());
    }

    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    if !engine.set_user(&me.id) {
        return Err("BUSY".into());
    }
    auth::save_token(&token).map_err(|e| format!("KEYRING: {e}"))?;
    if let Err(err) = engine.db().adopt_placeholder_user(&me.id) {
        tracing::error!(?err, "failed to adopt pre-login sessions");
    }
    remember_me(&mut engine, &me);
    drop(engine);
    *state.session.lock().map_err(|_| "session lock poisoned")? = Some(me.clone());
    state.sync.trigger(); // send anything queued for this user right away
    Ok(me)
}

#[tauri::command]
pub fn get_session(state: State<'_, AppState>) -> Result<Option<MeDto>, String> {
    Ok(state.session.lock().map_err(|_| "session lock poisoned")?.clone())
}

/// Best effort: refreshes `Me` (e.g. a raised consent version). A network failure just
/// keeps the cached copy; a rejected token is the sync worker's job to report.
#[tauri::command]
pub async fn refresh_me(state: State<'_, AppState>) -> Result<Option<MeDto>, String> {
    let current = state.session.lock().map_err(|_| "session lock poisoned")?.clone();
    let (Some(_), Some(token)) = (current.as_ref(), auth::load_token()) else {
        return Ok(current);
    };
    if let Ok(me) = state.api.me(&token).await {
        if me.status == "active" {
            let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
            remember_me(&mut engine, &me);
            *state.session.lock().map_err(|_| "session lock poisoned")? = Some(me);
        }
    }
    Ok(state.session.lock().map_err(|_| "session lock poisoned")?.clone())
}

#[tauri::command]
pub async fn accept_consent(state: State<'_, AppState>) -> Result<MeDto, String> {
    let (version, token) = {
        let session = state.session.lock().map_err(|_| "session lock poisoned")?;
        let me = session.as_ref().ok_or("NOT_LOGGED_IN")?;
        (me.office_settings.consent_version, auth::load_token().ok_or("NOT_LOGGED_IN")?)
    };
    let me = state.api.accept_consent(&token, version).await.map_err(api_error_string)?;
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    remember_me(&mut engine, &me);
    *state.session.lock().map_err(|_| "session lock poisoned")? = Some(me.clone());
    Ok(me)
}

/// Stops tracking, tries to send everything within 30 seconds, then signs out (docs §6.3).
/// Offline or too slow: signs out anyway and reports `synced: false`; the queued data stays
/// keyed to this user and goes out the next time they log in.
#[tauri::command]
pub async fn logout(state: State<'_, AppState>) -> Result<LogoutDto, String> {
    let user_id = stop_and_flush(&state, std::time::Duration::from_secs(30)).await?;
    let pending_count = state
        .engine
        .lock()
        .map_err(|_| "engine lock poisoned")?
        .db()
        .pending_count(&user_id)
        .unwrap_or(0);
    end_session(&state);
    Ok(LogoutDto {
        synced: pending_count == 0,
        pending_count,
    })
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncStatusDto {
    pub pending_count: i64,
    pub last_sync_at: Option<i64>,
    pub last_error: Option<String>,
    pub online: bool,
    pub needs_login: bool,
    pub upgrade_required: bool,
}

#[tauri::command]
pub fn get_sync_status(state: State<'_, AppState>) -> Result<SyncStatusDto, String> {
    let status = state.sync.status();
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    Ok(SyncStatusDto {
        pending_count: engine.db().pending_count(engine.user_id()).unwrap_or(0),
        last_sync_at: status.last_sync_at,
        last_error: status.last_error,
        online: status.online,
        needs_login: status.needs_login,
        upgrade_required: status.upgrade_required,
    })
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TrackingStateDto {
    pub state: &'static str,
    pub open_session_id: Option<String>,
    pub open_session_app: Option<String>,
    pub open_session_started_at: Option<i64>,
    /// The task currently picked, if any (see `get_my_tasks` for the list it comes from).
    pub current_task_id: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TodaySummaryDto {
    /// Milliseconds as of this call; the screen adds the time since the call to the
    /// `live_kind` counter so it advances smoothly, one second at a time.
    pub active_ms: i64,
    pub idle_ms: i64,
    /// `ACTIVE` or `IDLE` while a session is open and counting, else `None`.
    pub live_kind: Option<&'static str>,
    /// Time per app since the counters' start point, most first.
    pub apps: Vec<AppTimeDto>,
    /// What is in front right now (only while tracking and not idle). The title is `None`
    /// when the office keeps app names only.
    pub current_app: Option<String>,
    pub current_title: Option<String>,
    /// When this tracking run began (Start), for the work-period timer.
    pub work_started_at: Option<i64>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SessionDto {
    pub id: String,
    pub session_type: &'static str,
    pub app_name: Option<String>,
    pub idle_app_name: Option<String>,
    pub started_at: i64,
    pub ended_at: Option<i64>,
    pub duration_seconds: Option<i64>,
    pub sync_status: String,
}

fn state_str(state: TrackingState) -> &'static str {
    match state {
        TrackingState::NotTracking => "not_tracking",
        TrackingState::Tracking => "tracking",
        TrackingState::Paused => "paused",
        TrackingState::Away => "away",
    }
}

fn tracking_state_dto(engine: &Engine<SystemClock>) -> TrackingStateDto {
    let open = engine.open_session_info();
    TrackingStateDto {
        state: state_str(engine.state()),
        open_session_id: open.as_ref().map(|(id, ..)| id.clone()),
        open_session_app: None,
        open_session_started_at: open.as_ref().map(|(_, _, started_at)| *started_at),
        current_task_id: engine.current_task_id().map(str::to_owned),
    }
}

/// Local machine's midnight-to-now, in UTC milliseconds. Office-timezone-aware "today"
/// boundaries are a Phase 4 concern once real settings sync exists; this is a
/// reasonable default for Phase 3's offline debug/summary views.
fn today_range_ms() -> (i64, i64) {
    let now_local = chrono::Local::now();
    let start_of_day = now_local.date_naive().and_hms_opt(0, 0, 0).unwrap();
    let start_utc = chrono::Local
        .from_local_datetime(&start_of_day)
        .unwrap()
        .with_timezone(&chrono::Utc);
    (start_utc.timestamp_millis(), chrono::Utc::now().timestamp_millis())
}

fn session_type_str(t: db::SessionType) -> &'static str {
    match t {
        db::SessionType::Application => "application",
        db::SessionType::Idle => "idle",
    }
}

fn rows_to_dtos(rows: Vec<db::SessionRow>) -> Vec<SessionDto> {
    rows.into_iter()
        .map(|r| SessionDto {
            id: r.id,
            session_type: session_type_str(r.session_type),
            app_name: r.app_name,
            idle_app_name: r.idle_app_name,
            started_at: r.started_at,
            ended_at: r.ended_at,
            duration_seconds: r.duration_seconds,
            sync_status: r.sync_status,
        })
        .collect()
}

fn today_sessions(engine: &Engine<SystemClock>) -> Result<Vec<SessionDto>, String> {
    let (start_ms, end_ms) = today_range_ms();
    let rows = engine
        .db()
        .sessions_for_range(engine.user_id(), start_ms, end_ms + 1)
        .map_err(|e| e.to_string())?;
    Ok(rows_to_dtos(rows))
}

#[tauri::command]
pub fn start_tracking(state: State<'_, AppState>) -> Result<TrackingStateDto, String> {
    require_ready(&state)?;
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    engine.start();
    Ok(tracking_state_dto(&engine))
}

#[tauri::command]
pub fn pause_tracking(state: State<'_, AppState>) -> Result<TrackingStateDto, String> {
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    engine.pause();
    Ok(tracking_state_dto(&engine))
}

#[tauri::command]
pub fn resume_tracking(state: State<'_, AppState>) -> Result<TrackingStateDto, String> {
    require_ready(&state)?;
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    engine.resume();
    Ok(tracking_state_dto(&engine))
}

#[tauri::command]
pub fn stop_tracking(state: State<'_, AppState>) -> Result<TrackingStateDto, String> {
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    engine.stop();
    state.sync.trigger(); // the closed session goes out right away (docs §11.1)
    Ok(tracking_state_dto(&engine))
}

#[tauri::command]
pub fn get_tracking_state(state: State<'_, AppState>) -> Result<TrackingStateDto, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    Ok(tracking_state_dto(&engine))
}

#[tauri::command]
pub fn get_today_summary(state: State<'_, AppState>) -> Result<TodaySummaryDto, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    let (day_start_ms, end_ms) = today_range_ms();
    // "Reset" on the screen only moves the point the counters start from; nothing is deleted.
    let reset_at_ms = engine
        .db()
        .get_app_state("counters_reset_at")
        .and_then(|v| v.parse::<i64>().ok())
        .unwrap_or(0);
    let start_ms = day_start_ms.max(reset_at_ms);
    let rows = engine
        .db()
        .sessions_for_range(engine.user_id(), start_ms, end_ms + 1)
        .map_err(|e| e.to_string())?;

    let mut active_ms = 0i64;
    let mut idle_ms = 0i64;
    for row in &rows {
        let ms = row.duration_seconds.unwrap_or(0) * 1000;
        match row.session_type {
            db::SessionType::Application => active_ms += ms,
            db::SessionType::Idle => idle_ms += ms,
        }
    }

    // The still-open session's duration isn't saved yet (NULL until closed), so add what
    // has been counted on screen so far.
    let (open_active_ms, open_idle_ms) = engine.open_live_ms();
    active_ms += open_active_ms;
    idle_ms += open_idle_ms;
    let live_kind = engine.open_session_info().map(|(_, kind, _)| match kind {
        SessionKind::Active => "active",
        SessionKind::Idle => "idle",
    });

    let open_activity = engine.open_activity();
    let (current_app, current_title) = open_activity.clone().unwrap_or((None, None));
    let open_app = current_app.as_deref().map(|name| (name, open_active_ms));
    let apps = build_app_list(&rows, open_app);

    Ok(TodaySummaryDto {
        active_ms,
        idle_ms,
        live_kind,
        apps,
        current_app,
        current_title,
        work_started_at: engine.status_snapshot().tracking_started_at.map(|t| t.timestamp_millis()),
    })
}

/// Zeroes the on-screen counters (today's Tracked / Active / Idle). Refused while tracking,
/// so a running session is never split by it. No session is deleted or changed, and
/// everything is still sent to the server.
#[tauri::command]
pub fn reset_today_counters(state: State<'_, AppState>) -> Result<(), String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    if engine.state() != TrackingState::NotTracking {
        return Err("STOP_FIRST".into());
    }
    engine
        .db()
        .set_app_state("counters_reset_at", &chrono::Utc::now().timestamp_millis().to_string())
        .map_err(|e| e.to_string())
}

/// Today's sessions as display blocks: adjacent same-app chunks merged, idle labelled
/// "Idle (in <app>)". Covers the whole day; the counters' Reset does not affect it.
#[tauri::command]
pub fn get_today_timeline(state: State<'_, AppState>) -> Result<Vec<SegmentDto>, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    let (start_ms, end_ms) = today_range_ms();
    let rows = engine
        .db()
        .sessions_for_range(engine.user_id(), start_ms, end_ms + 1)
        .map_err(|e| e.to_string())?;
    Ok(build_timeline(&rows, end_ms))
}

#[tauri::command]
pub fn get_launch_at_startup(app: AppHandle) -> bool {
    app.autolaunch().is_enabled().unwrap_or(false)
}

#[tauri::command]
pub fn set_launch_at_startup(app: AppHandle, enabled: bool) -> Result<(), String> {
    let launcher = app.autolaunch();
    if enabled {
        launcher.enable().map_err(|e| e.to_string())
    } else {
        launcher.disable().map_err(|e| e.to_string())
    }
}

#[tauri::command]
pub fn open_log_folder(app: AppHandle) -> Result<(), String> {
    let dir = app.path().app_log_dir().map_err(|e| e.to_string())?;
    std::fs::create_dir_all(&dir).map_err(|e| e.to_string())?;
    std::process::Command::new("explorer")
        .arg(&dir)
        .spawn()
        .map(|_| ())
        .map_err(|e| e.to_string())
}

/// Opens the dashboard's "Forgot password" page in the default browser. The address is built here
/// from the configured dashboard root; nothing from the screen goes into it.
#[tauri::command]
pub fn open_forgot_password(state: State<'_, AppState>) -> Result<(), String> {
    let root = {
        let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
        crate::api::dashboard_root(engine.db())
    };
    let url = crate::api::forgot_password_url(&root);
    // `start` treats the first quoted argument as a window title, hence the empty one.
    std::process::Command::new("cmd")
        .args(["/C", "start", "", &url])
        .spawn()
        .map(|_| ())
        .map_err(|e| e.to_string())
}

#[tauri::command]
pub fn get_app_version(app: AppHandle) -> String {
    app.package_info().version.to_string()
}

#[tauri::command]
pub fn get_today_sessions_debug(state: State<'_, AppState>) -> Result<Vec<SessionDto>, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    today_sessions(&engine)
}

// ---- tasks (docs/DEVELOPMENT_PLAN.md) --------------------------------------------------------------

/// The caller's own active, assigned tasks, as cached from the last sync (`app_state["tasks_json"]`) --
/// no network call, so the picker still works offline. Empty until the first sync after login.
#[tauri::command]
pub fn get_my_tasks(state: State<'_, AppState>) -> Result<Vec<crate::sync::client::TaskDto>, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    let json = engine.db().get_app_state("tasks_json").unwrap_or_else(|| "[]".into());
    Ok(serde_json::from_str(&json).unwrap_or_default())
}

/// The employee picked a task to work on, or cleared it (`task_id: None`). Closes the currently open
/// session, if any, and reopens one tagged with the new task.
#[tauri::command]
pub fn set_current_task(state: State<'_, AppState>, task_id: Option<String>) -> Result<TrackingStateDto, String> {
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    engine.set_current_task(task_id);
    Ok(tracking_state_dto(&engine))
}

/// Today's tracked time per assigned task, plus "No task", for the Tasks screen's list. A task's total
/// counts idle time too (it is "how long it was picked", not "how long there was active use"), unlike
/// the app-usage breakdown.
#[tauri::command]
pub fn get_today_tasks(state: State<'_, AppState>) -> Result<Vec<TaskTimeDto>, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    let (day_start_ms, end_ms) = today_range_ms();
    let reset_at_ms = engine
        .db()
        .get_app_state("counters_reset_at")
        .and_then(|v| v.parse::<i64>().ok())
        .unwrap_or(0);
    let start_ms = day_start_ms.max(reset_at_ms);
    let rows = engine
        .db()
        .sessions_for_range(engine.user_id(), start_ms, end_ms + 1)
        .map_err(|e| e.to_string())?;

    let (open_active_ms, open_idle_ms) = engine.open_live_ms();
    let open = engine.open_session_info().map(|_| (engine.current_task_id(), open_active_ms + open_idle_ms));

    let known: Vec<(String, String)> = {
        let json = engine.db().get_app_state("tasks_json").unwrap_or_else(|| "[]".into());
        serde_json::from_str::<Vec<crate::sync::client::TaskDto>>(&json)
            .unwrap_or_default()
            .into_iter()
            .map(|t| (t.id, t.title))
            .collect()
    };

    Ok(build_task_list(&rows, open, &known))
}

// ---- screenshots (docs phase 10) -------------------------------------------------------------------

/// What the app shows about screenshots: whether they are on, how often, what is waiting, the last one.
#[tauri::command]
pub fn get_screenshot_status(state: State<'_, AppState>) -> Result<crate::screenshot::ScreenshotStatus, String> {
    let user_id = state.logged_in_user_id().ok_or("NOT_LOGGED_IN")?;
    Ok(crate::screenshot::status(&state.engine, &user_id))
}

/// The signed-in person's own screenshots for one office day (`YYYY-MM-DD`), read from the server.
#[tauri::command]
pub async fn list_my_screenshots(state: State<'_, AppState>, day: String) -> Result<Vec<crate::screenshot::upload::ScreenshotItem>, String> {
    let user_id = state.logged_in_user_id().ok_or("NOT_LOGGED_IN")?;
    let token = auth::load_token().ok_or("NOT_LOGGED_IN")?;
    crate::screenshot::upload::list_own(&state.api, &token, &user_id, &day).await.map_err(api_error_string)
}

/// One of the person's own pictures ("thumb" or "image") as a data URL the page can show.
#[tauri::command]
pub async fn get_my_screenshot(state: State<'_, AppState>, id: String, kind: String) -> Result<String, String> {
    use base64::Engine as _;
    if kind != "thumb" && kind != "image" {
        return Err("BAD_KIND".into());
    }
    let token = auth::load_token().ok_or("NOT_LOGGED_IN")?;
    let bytes = crate::screenshot::upload::fetch_own(&state.api, &token, &id, &kind).await.map_err(api_error_string)?;
    Ok(format!("data:image/jpeg;base64,{}", base64::engine::general_purpose::STANDARD.encode(bytes)))
}
