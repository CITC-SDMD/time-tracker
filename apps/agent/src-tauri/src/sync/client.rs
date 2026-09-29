//! `POST /api/v1/agent/sync` on the wire (docs §10.1); mirrors packages/shared
//! `AgentSyncRequest` / `AgentSyncResponse`.

use chrono::{DateTime, TimeZone, Utc};
use reqwest::Method;
use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::api::{map_transport, read_json, ApiClient, ApiError};

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncRequest {
    pub client_time: String,
    pub computer_name: Option<String>,
    pub db_reset: bool,
    pub status: SyncStatusDto,
    pub sessions: Vec<Value>,
    /// Tasks the employee marked complete or reopened since the last sync (per person, not per task).
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub completed_task_ids: Vec<String>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub reopened_task_ids: Vec<String>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncStatusDto {
    pub state: &'static str,
    pub current_app: Option<String>,
    pub idle_app_name: Option<String>,
    pub since: Option<String>,
    pub tracking_started_at: Option<String>,
    /// "physical", "virtual_machine" or "remote_session"; left out when the server has detection off for this person.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub environment: Option<&'static str>,
    /// Known macro programs found running (names from the server's list only); left out when there are none.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub macro_tools: Vec<String>,
}

#[derive(Debug, Deserialize)]
pub struct Rejected {
    pub id: String,
    pub reason: String,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncCommands {
    #[serde(default)]
    pub stop_tracking: bool,
    pub stop_reason: Option<String>,
    #[serde(default)]
    pub sign_out: bool,
    /// A superadmin can switch the virtual machine detection off for a person. Older servers do not say: it stays on.
    #[serde(default = "detection_default")]
    pub detection_enabled: bool,
}

fn detection_default() -> bool {
    true
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncSettings {
    pub idle_threshold_seconds: u64,
    pub window_title_mode: String,
    /// Older servers do not send these two: screenshots then stay off.
    #[serde(default)]
    pub screenshot_interval_minutes: u32,
    #[serde(default)]
    pub screenshot_random: bool,
    /// The programs to look for by name (the activity check). Older servers do not send it: the built-in list is used.
    #[serde(default)]
    pub macro_tools: Vec<String>,
}

/// A task as the agent sees it: just enough to show and let the employee pick from
/// (only the caller's own active, assigned tasks -- see App\Services\TaskService on the server).
#[derive(Debug, Clone, Deserialize, Serialize, PartialEq, Eq)]
pub struct TaskDto {
    pub id: String,
    pub title: String,
    /// Whether the caller has marked their own part of this task done. Older servers do not send it: treated
    /// as not completed, which just means the "Mark complete" affordance shows instead of "Completed".
    #[serde(default)]
    pub completed: bool,
}

#[derive(Debug, Deserialize)]
pub struct SyncResponse {
    pub accepted: Vec<String>,
    pub duplicates: Vec<String>,
    pub rejected: Vec<Rejected>,
    pub commands: SyncCommands,
    /// Older servers do not send this: the picker just stays empty until an upgrade.
    #[serde(default)]
    pub tasks: Vec<TaskDto>,
    pub settings: SyncSettings,
}

pub async fn post_sync(api: &ApiClient, token: &str, request: &SyncRequest) -> Result<SyncResponse, ApiError> {
    let resp = api
        .request(Method::POST, "/agent/sync", Some(token))
        .json(request)
        .send()
        .await
        .map_err(map_transport)?;
    read_json(resp).await
}

pub fn iso(ms: i64) -> String {
    let time: DateTime<Utc> = Utc.timestamp_millis_opt(ms).single().unwrap_or_default();
    time.format("%Y-%m-%dT%H:%M:%S%.3fZ").to_string()
}

pub fn iso_from(time: DateTime<Utc>) -> String {
    iso(time.timestamp_millis())
}

/// A `sync_queue.payload` holds UTC milliseconds; the API wants ISO 8601 strings.
/// `None` means the stored payload is unreadable and can never be sent.
pub fn wire_session(payload: &str) -> Option<Value> {
    let mut value: Value = serde_json::from_str(payload).ok()?;
    let object = value.as_object_mut()?;
    for key in ["startedAt", "endedAt"] {
        let ms = object.get(key)?.as_i64()?;
        object.insert(key.to_owned(), Value::String(iso(ms)));
    }
    Some(value)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn payload_timestamps_become_iso_strings() {
        let payload = r#"{"id":"a","type":"application","appName":"Code","processName":"Code.exe","windowTitle":null,"idleAppName":null,"startedAt":1758794400000,"endedAt":1758794700123,"durationSeconds":300,"clockChanged":false}"#;

        let wire = wire_session(payload).unwrap();

        assert_eq!(wire["startedAt"], "2025-09-25T10:00:00.000Z");
        assert_eq!(wire["endedAt"], "2025-09-25T10:05:00.123Z");
        assert_eq!(wire["durationSeconds"], 300);
        assert_eq!(wire["type"], "application");
    }

    #[test]
    fn detection_is_on_unless_the_server_says_otherwise() {
        let older: SyncCommands = serde_json::from_str(r#"{"stopTracking":false,"stopReason":null,"signOut":false}"#).unwrap();
        assert!(older.detection_enabled);
        let off: SyncCommands = serde_json::from_str(r#"{"detectionEnabled":false}"#).unwrap();
        assert!(!off.detection_enabled);
    }

    #[test]
    fn the_environment_is_left_out_of_the_status_when_detection_is_off() {
        let status = |environment| SyncStatusDto {
            state: "active",
            current_app: None,
            idle_app_name: None,
            since: None,
            tracking_started_at: None,
            environment,
            macro_tools: Vec::new(),
        };
        assert_eq!(serde_json::to_value(status(Some("virtual_machine"))).unwrap()["environment"], "virtual_machine");
        assert!(serde_json::to_value(status(None)).unwrap().get("environment").is_none());
    }

    #[test]
    fn unreadable_payloads_are_reported_as_none() {
        assert!(wire_session("not json").is_none());
        assert!(wire_session(r#"{"id":"a"}"#).is_none());
        assert!(wire_session(r#"{"startedAt":"x","endedAt":1}"#).is_none());
    }
}
