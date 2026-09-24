//! Commands the Vue side can call with `invoke(...)`.

use std::collections::VecDeque;
use std::path::PathBuf;
use std::sync::{Arc, Mutex};

use serde::Serialize;
use tauri::State;

use crate::platform::{ActivityProvider, SystemEvent};

pub const MAX_RECENT_EVENTS: usize = 50;

pub struct AppState {
    pub provider: Arc<dyn ActivityProvider>,
    pub recent_events: Arc<Mutex<VecDeque<SystemEventRecord>>>,
    pub log_path: PathBuf,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CurrentActivity {
    pub application: Option<String>,
    pub process_name: Option<String>,
    pub window_title: Option<String>,
    pub idle_seconds: u64,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SystemEventRecord {
    pub kind: SystemEvent,
    /// RFC 3339, local time.
    pub at: String,
}

#[tauri::command]
pub fn get_current_activity(state: State<'_, AppState>) -> CurrentActivity {
    let app = state.provider.current_activity();
    CurrentActivity {
        application: app.as_ref().map(|a| a.app_name.clone()),
        process_name: app.as_ref().map(|a| a.process_name.clone()),
        window_title: app.map(|a| a.window_title),
        idle_seconds: state.provider.idle_seconds(),
    }
}

#[tauri::command]
pub fn get_recent_events(state: State<'_, AppState>) -> Vec<SystemEventRecord> {
    let events = state
        .recent_events
        .lock()
        .unwrap_or_else(|e| e.into_inner());
    events.iter().cloned().collect()
}

#[tauri::command]
pub fn get_spike_log_path(state: State<'_, AppState>) -> String {
    state.log_path.display().to_string()
}
