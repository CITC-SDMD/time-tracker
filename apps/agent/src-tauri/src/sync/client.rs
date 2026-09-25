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
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncStatusDto {
    pub state: &'static str,
    pub current_app: Option<String>,
    pub idle_app_name: Option<String>,
    pub since: Option<String>,
    pub tracking_started_at: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct Rejected {
    pub id: String,
    pub reason: String,
}

#[derive(Debug, Default, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncCommands {
    #[serde(default)]
    pub stop_tracking: bool,
    pub stop_reason: Option<String>,
    #[serde(default)]
    pub sign_out: bool,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SyncSettings {
    pub idle_threshold_seconds: u64,
    pub window_title_mode: String,
}

#[derive(Debug, Deserialize)]
pub struct SyncResponse {
    pub accepted: Vec<String>,
    pub duplicates: Vec<String>,
    pub rejected: Vec<Rejected>,
    pub commands: SyncCommands,
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
    fn unreadable_payloads_are_reported_as_none() {
        assert!(wire_session("not json").is_none());
        assert!(wire_session(r#"{"id":"a"}"#).is_none());
        assert!(wire_session(r#"{"startedAt":"x","endedAt":1}"#).is_none());
    }
}
