//! Thin HTTP client for the Laravel API (docs §10): base URL, the headers every request
//! carries, and the mapping from HTTP failures to `ApiError`. The sync endpoint itself
//! is in `sync/client.rs`, on top of this.

use std::time::Duration;

use reqwest::{Method, RequestBuilder, StatusCode};
use serde::{Deserialize, Serialize};

use crate::db::Db;

pub const AGENT_VERSION: &str = env!("CARGO_PKG_VERSION");

/// Server root, without `/api/v1` (`/health` sits outside the versioned prefix).
const DEFAULT_ROOT: &str = "http://127.0.0.1:8000";
const DEV_DASHBOARD_ROOT: &str = "http://localhost:3100";

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ApiError {
    /// No connection, DNS failure, TLS failure, timeout.
    Offline,
    WrongPassword,
    Deactivated,
    /// 401 on an authenticated call: the stored token is no longer valid.
    Unauthorized,
    /// 426: this agent is older than `office_settings.min_agent_version`.
    UpgradeRequired,
    RateLimited,
    Server(u16),
    Other { code: String, message: String },
}

impl ApiError {
    /// The short code the Vue side switches on.
    pub fn code(&self) -> String {
        match self {
            Self::Offline => "OFFLINE".into(),
            Self::WrongPassword => "WRONG_PASSWORD".into(),
            Self::Deactivated => "DEACTIVATED".into(),
            Self::Unauthorized => "UNAUTHORIZED".into(),
            Self::UpgradeRequired => "UPGRADE_REQUIRED".into(),
            Self::RateLimited => "RATE_LIMITED".into(),
            Self::Server(_) => "SERVER_ERROR".into(),
            Self::Other { code, .. } => code.clone(),
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct OfficeSettingsDto {
    pub timezone: String,
    pub idle_threshold_seconds: u64,
    pub window_title_mode: String,
    pub min_agent_version: String,
    pub consent_version: i64,
}

/// `GET /me` (packages/shared `Me`).
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct MeDto {
    pub id: String,
    pub name: String,
    pub email: String,
    pub role: String,
    pub status: String,
    pub consent_version: Option<i64>,
    pub consent_required: bool,
    pub office_settings: OfficeSettingsDto,
}

#[derive(Deserialize)]
struct LoginResponse {
    token: String,
    me: MeDto,
}

#[derive(Deserialize)]
struct ErrorBody {
    error: ErrorInner,
}

#[derive(Deserialize)]
struct ErrorInner {
    code: String,
    message: String,
}

/// Where the dashboard lives, for links opened in the browser. Order: `app_state.dashboard_base_url`
/// (test override), the `TRACKER_DASHBOARD_URL` build-time variable, then the API root (the two
/// share one address in production; local development runs them on different ports).
pub fn dashboard_root(db: &Db) -> String {
    db.get_app_state("dashboard_base_url")
        .or_else(|| option_env!("TRACKER_DASHBOARD_URL").map(str::to_owned))
        .or_else(|| db.get_app_state("api_base_url"))
        .or_else(|| option_env!("TRACKER_API_URL").map(str::to_owned))
        // Nothing configured: a development build talks to the local API, and its dashboard is on
        // the Nuxt dev server; a release build assumes one shared address.
        .unwrap_or_else(|| if cfg!(debug_assertions) { DEV_DASHBOARD_ROOT } else { DEFAULT_ROOT }.to_owned())
}

/// The dashboard page where someone asks for a password-reset link.
pub fn forgot_password_url(root: &str) -> String {
    format!("{}/forgot-password", root.trim_end_matches('/'))
}

#[derive(Clone)]
pub struct ApiClient {
    http: reqwest::Client,
    root: String,
    device_id: String,
}

impl ApiClient {
    /// Root order: `app_state.api_base_url` (test override), the `TRACKER_API_URL` build-time
    /// variable, then the local-dev default.
    pub fn from_db(db: &Db) -> Self {
        let root = db
            .get_app_state("api_base_url")
            .or_else(|| option_env!("TRACKER_API_URL").map(str::to_owned))
            .unwrap_or_else(|| DEFAULT_ROOT.to_owned());
        let device_id = db.get_app_state("device_id").unwrap_or_default();
        Self::new(&root, &device_id)
    }

    pub fn new(root: &str, device_id: &str) -> Self {
        let http = reqwest::Client::builder()
            .timeout(Duration::from_secs(20))
            .connect_timeout(Duration::from_secs(10))
            .build()
            .expect("reqwest client builds with default TLS");
        Self {
            http,
            root: root.trim_end_matches('/').to_owned(),
            device_id: device_id.to_owned(),
        }
    }

    pub fn request(&self, method: Method, path: &str, token: Option<&str>) -> RequestBuilder {
        let mut req = self
            .http
            .request(method, format!("{}/api/v1{}", self.root, path))
            .header("X-Agent-Version", AGENT_VERSION)
            .header("X-Device-Id", &self.device_id)
            .header("Accept", "application/json");
        if let Some(token) = token {
            req = req.bearer_auth(token);
        }
        req
    }

    /// `GET /health` — unauthenticated, outside `/api/v1`. Used to notice "internet is back".
    pub async fn health(&self) -> bool {
        match self.http.get(format!("{}/health", self.root)).send().await {
            Ok(resp) => resp.status().is_success(),
            Err(_) => false,
        }
    }

    pub async fn login(&self, email: &str, password: &str) -> Result<(String, MeDto), ApiError> {
        let body = serde_json::json!({ "email": email, "password": password });
        let resp = self
            .request(Method::POST, "/auth/login", None)
            .json(&body)
            .send()
            .await
            .map_err(map_transport)?;
        let parsed: LoginResponse = read_json(resp).await?;
        Ok((parsed.token, parsed.me))
    }

    pub async fn me(&self, token: &str) -> Result<MeDto, ApiError> {
        let resp = self
            .request(Method::GET, "/me", Some(token))
            .send()
            .await
            .map_err(map_transport)?;
        read_json(resp).await
    }

    pub async fn accept_consent(&self, token: &str, consent_version: i64) -> Result<MeDto, ApiError> {
        let body = serde_json::json!({ "consentVersion": consent_version });
        let resp = self
            .request(Method::POST, "/me/consent", Some(token))
            .json(&body)
            .send()
            .await
            .map_err(map_transport)?;
        read_json(resp).await
    }
}

pub fn map_transport(_err: reqwest::Error) -> ApiError {
    ApiError::Offline
}

/// Decodes a 2xx body as `T`, or turns the response into the matching `ApiError`.
pub async fn read_json<T: serde::de::DeserializeOwned>(resp: reqwest::Response) -> Result<T, ApiError> {
    let status = resp.status();
    if status.is_success() {
        return resp.json::<T>().await.map_err(|e| ApiError::Other {
            code: "BAD_RESPONSE".into(),
            message: e.to_string(),
        });
    }
    Err(error_from_response(status, resp).await)
}

pub async fn error_from_response(status: StatusCode, resp: reqwest::Response) -> ApiError {
    let body = resp.json::<ErrorBody>().await.ok();
    let code = body.as_ref().map(|b| b.error.code.as_str()).unwrap_or("");
    match (status.as_u16(), code) {
        (401, "WRONG_PASSWORD") => ApiError::WrongPassword,
        (401, _) => ApiError::Unauthorized,
        (403, "ACCOUNT_DEACTIVATED") => ApiError::Deactivated,
        (426, _) => ApiError::UpgradeRequired,
        (429, _) => ApiError::RateLimited,
        (s, _) if s >= 500 => ApiError::Server(s),
        _ => ApiError::Other {
            code: if code.is_empty() { status.as_u16().to_string() } else { code.to_owned() },
            message: body.map(|b| b.error.message).unwrap_or_default(),
        },
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::testutil::{dead_root, MockServer};

    const ME: &str = r#"{"id":"42","name":"Ana","email":"ana@example.com","role":"DEVELOPER","status":"ACTIVE","consentVersion":null,"consentRequired":true,"officeSettings":{"timezone":"Asia/Manila","idleThresholdSeconds":300,"windowTitleMode":"FULL","minAgentVersion":"0.1.0","consentVersion":1}}"#;

    fn error_body(code: &str) -> String {
        format!(r#"{{"error":{{"code":"{code}","message":"x"}}}}"#)
    }

    #[test]
    fn forgot_password_url_joins_the_dashboard_root_without_a_double_slash() {
        assert_eq!(forgot_password_url("https://t.example.com"), "https://t.example.com/forgot-password");
        assert_eq!(forgot_password_url("http://localhost:3100/"), "http://localhost:3100/forgot-password");
    }

    #[test]
    fn dashboard_root_prefers_its_own_override_then_the_api_override() {
        let db = Db::open_in_memory_for_test().unwrap();
        db.set_app_state("api_base_url", "http://api.test").unwrap();
        assert_eq!(dashboard_root(&db), "http://api.test");
        db.set_app_state("dashboard_base_url", "http://dash.test").unwrap();
        assert_eq!(dashboard_root(&db), "http://dash.test");
    }

    #[tokio::test]
    async fn login_returns_the_token_and_me_and_sends_the_agent_headers() {
        let server = MockServer::start(vec![(200, format!(r#"{{"token":"tok","me":{ME}}}"#))]).await;
        let client = ApiClient::new(&server.root, "device-1");

        let (token, me) = client.login("ana@example.com", "pw").await.unwrap();

        assert_eq!(token, "tok");
        assert_eq!(me.id, "42");
        assert!(me.consent_required);
        let request = server.request(0).to_lowercase();
        assert!(request.starts_with("post /api/v1/auth/login"));
        assert!(request.contains("x-device-id: device-1"));
        assert!(request.contains(&format!("x-agent-version: {}", AGENT_VERSION)));
    }

    #[tokio::test]
    async fn authenticated_calls_send_the_bearer_token() {
        let server = MockServer::start(vec![(200, ME.to_owned())]).await;
        let client = ApiClient::new(&server.root, "d");

        client.me("secret-token").await.unwrap();

        assert!(server.request(0).to_lowercase().contains("authorization: bearer secret-token"));
    }

    #[tokio::test]
    async fn error_responses_map_to_typed_errors() {
        let server = MockServer::start(vec![
            (401, error_body("WRONG_PASSWORD")),
            (403, error_body("ACCOUNT_DEACTIVATED")),
            (401, error_body("UNAUTHENTICATED")),
            (426, error_body("UPGRADE_REQUIRED")),
            (429, error_body("TOO_MANY_REQUESTS")),
            (503, "oops".to_owned()),
        ])
        .await;
        let client = ApiClient::new(&server.root, "d");

        assert_eq!(client.login("a", "b").await.unwrap_err(), ApiError::WrongPassword);
        assert_eq!(client.login("a", "b").await.unwrap_err(), ApiError::Deactivated);
        assert_eq!(client.me("t").await.unwrap_err(), ApiError::Unauthorized);
        assert_eq!(client.me("t").await.unwrap_err(), ApiError::UpgradeRequired);
        assert_eq!(client.me("t").await.unwrap_err(), ApiError::RateLimited);
        assert_eq!(client.me("t").await.unwrap_err(), ApiError::Server(503));
    }

    #[tokio::test]
    async fn an_unreachable_server_is_reported_as_offline() {
        let client = ApiClient::new(&dead_root().await, "d");

        assert_eq!(client.login("a", "b").await.unwrap_err(), ApiError::Offline);
        assert!(!client.health().await);
    }

    #[tokio::test]
    async fn health_is_true_when_the_server_answers_ok() {
        let server = MockServer::start(vec![(200, r#"{"ok":true}"#.to_owned())]).await;

        assert!(ApiClient::new(&server.root, "d").health().await);
        assert!(server.request(0).starts_with("GET /health"));
    }
}
