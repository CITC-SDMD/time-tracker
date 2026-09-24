//! Commands the Vue side can call with `invoke(...)`.

use std::sync::{Arc, Mutex};

use chrono::TimeZone;
use serde::Serialize;
use tauri::State;
use tracing_appender::non_blocking::WorkerGuard;

use crate::db;
use crate::tracker::clock::SystemClock;
use crate::tracker::engine::Engine;
use crate::tracker::state::{SessionKind, TrackingState};

pub struct AppState {
    pub engine: Arc<Mutex<Engine<SystemClock>>>,
    /// Kept alive for the app's lifetime -- dropping it stops tracing-appender's flush thread.
    pub _log_guard: WorkerGuard,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TrackingStateDto {
    pub state: &'static str,
    pub open_session_id: Option<String>,
    pub open_session_app: Option<String>,
    pub open_session_started_at: Option<i64>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TodaySummaryDto {
    pub active_seconds: i64,
    pub idle_seconds: i64,
    pub tracked_seconds: i64,
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
        TrackingState::NotTracking => "NOT_TRACKING",
        TrackingState::Tracking => "TRACKING",
        TrackingState::Paused => "PAUSED",
        TrackingState::Away => "AWAY",
    }
}

fn tracking_state_dto(engine: &Engine<SystemClock>) -> TrackingStateDto {
    let open = engine.open_session_info();
    TrackingStateDto {
        state: state_str(engine.state()),
        open_session_id: open.as_ref().map(|(id, ..)| id.clone()),
        open_session_app: None,
        open_session_started_at: open.as_ref().map(|(_, _, started_at)| *started_at),
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
        db::SessionType::Application => "APPLICATION",
        db::SessionType::Idle => "IDLE",
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
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    engine.resume();
    Ok(tracking_state_dto(&engine))
}

#[tauri::command]
pub fn stop_tracking(state: State<'_, AppState>) -> Result<TrackingStateDto, String> {
    let mut engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    engine.stop();
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
    let (start_ms, end_ms) = today_range_ms();
    let rows = engine
        .db()
        .sessions_for_range(engine.user_id(), start_ms, end_ms + 1)
        .map_err(|e| e.to_string())?;

    let mut active_seconds = 0i64;
    let mut idle_seconds = 0i64;
    for row in &rows {
        let secs = row.duration_seconds.unwrap_or(0);
        match row.session_type {
            db::SessionType::Application => active_seconds += secs,
            db::SessionType::Idle => idle_seconds += secs,
        }
    }

    // The still-open session's duration isn't in `duration_seconds` yet (NULL until
    // closed) -- add its elapsed time so far so "today" reflects live state.
    if let Some((_, kind, started_at)) = engine.open_session_info() {
        let elapsed = ((chrono::Utc::now().timestamp_millis() - started_at) / 1000).max(0);
        match kind {
            SessionKind::Active => active_seconds += elapsed,
            SessionKind::Idle => idle_seconds += elapsed,
        }
    }

    Ok(TodaySummaryDto {
        active_seconds,
        idle_seconds,
        tracked_seconds: active_seconds + idle_seconds,
    })
}

/// Phase 3 returns raw per-chunk sessions here, same shape as `get_today_sessions_debug`.
/// Merging adjacent same-app 10-minute chunks into single visual timeline blocks is a
/// Phase 5+ dashboard concern -- not built now.
#[tauri::command]
pub fn get_today_timeline(state: State<'_, AppState>) -> Result<Vec<SessionDto>, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    today_sessions(&engine)
}

#[tauri::command]
pub fn get_today_sessions_debug(state: State<'_, AppState>) -> Result<Vec<SessionDto>, String> {
    let engine = state.engine.lock().map_err(|_| "engine lock poisoned")?;
    today_sessions(&engine)
}
