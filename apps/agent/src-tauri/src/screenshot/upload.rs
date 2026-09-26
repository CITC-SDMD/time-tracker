//! Sending one screenshot to the server (`POST /agent/screenshots`, docs §10) and reading a person's
//! own pictures back. What the answers mean is decided here, so the queue logic only sees an `Outcome`.

use reqwest::multipart::{Form, Part};
use reqwest::Method;
use serde::Deserialize;

use crate::api::{error_from_response, ApiClient, ApiError};
use crate::db::ScreenshotRow;
use crate::sync::client::iso_from;

/// What happened to one upload, and so what to do with the local copy.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Outcome {
    /// The server has it (stored now, or a duplicate of an earlier try): delete the local copy.
    Sent,
    /// The server will never take it (switched off, refused as not valid): delete the local copy.
    Dropped,
    /// A temporary problem (storage down, busy, a server error): keep it and try again later.
    Retry,
    /// No connection: keep it, and wait for the internet.
    Offline,
    /// 401: the stored token is no longer valid. Data is kept.
    NeedsLogin,
    /// 426: this app is too old. Data is kept.
    UpgradeRequired,
}

pub async fn upload(api: &ApiClient, token: &str, row: &ScreenshotRow, jpeg: Vec<u8>) -> Outcome {
    let taken_at = chrono::DateTime::from_timestamp_millis(row.taken_at).unwrap_or_default();
    let Ok(image) = Part::bytes(jpeg).file_name("screen.jpg").mime_str("image/jpeg") else {
        return Outcome::Dropped;
    };
    let form = Form::new()
        .text("id", row.id.clone())
        .text("takenAt", iso_from(taken_at))
        .text("width", row.width.to_string())
        .text("height", row.height.to_string())
        .part("image", image);

    let response = match api.request(Method::POST, "/agent/screenshots", Some(token)).multipart(form).send().await {
        Ok(response) => response,
        Err(_) => return Outcome::Offline,
    };
    let status = response.status();
    if status.is_success() {
        return Outcome::Sent; // 201 stored, or 200 duplicate
    }
    match error_from_response(status, response).await {
        ApiError::Unauthorized => Outcome::NeedsLogin,
        ApiError::UpgradeRequired => Outcome::UpgradeRequired,
        ApiError::Offline => Outcome::Offline,
        ApiError::RateLimited | ApiError::Server(_) | ApiError::Deactivated | ApiError::WrongPassword => Outcome::Retry,
        // 409 SCREENSHOTS_DISABLED / CONFLICT and 422 VALIDATION_FAILED: sending it again cannot change the answer
        ApiError::Other { .. } => Outcome::Dropped,
    }
}

/// One entry of `GET /employees/{id}/screenshots` (packages/shared `ScreenshotItem`).
#[derive(Debug, Clone, serde::Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ScreenshotItem {
    pub id: String,
    pub taken_at: String,
    pub width: u32,
    pub height: u32,
}

/// The signed-in person's own screenshots for one office day (`YYYY-MM-DD`).
pub async fn list_own(api: &ApiClient, token: &str, user_id: &str, day: &str) -> Result<Vec<ScreenshotItem>, ApiError> {
    // the day is put in the address by hand, so it must be nothing but digits and dashes
    if day.is_empty() || !day.chars().all(|c| c.is_ascii_digit() || c == '-') {
        return Err(ApiError::Other { code: "BAD_DAY".into(), message: "not a day".into() });
    }
    let response = api
        .request(Method::GET, &format!("/employees/{user_id}/screenshots?day={day}"), Some(token))
        .send()
        .await
        .map_err(crate::api::map_transport)?;
    crate::api::read_json(response).await
}

/// One of the person's own pictures as bytes. `kind` is "thumb" or "image".
pub async fn fetch_own(api: &ApiClient, token: &str, id: &str, kind: &str) -> Result<Vec<u8>, ApiError> {
    let response = api
        .request(Method::GET, &format!("/screenshots/{id}/{kind}"), Some(token))
        .send()
        .await
        .map_err(crate::api::map_transport)?;
    let status = response.status();
    if !status.is_success() {
        return Err(error_from_response(status, response).await);
    }
    response.bytes().await.map(|b| b.to_vec()).map_err(crate::api::map_transport)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::testutil::{dead_root, MockServer};

    fn row() -> ScreenshotRow {
        ScreenshotRow {
            id: "0198a5a0-0000-7000-8000-000000000001".into(),
            user_id: "42".into(),
            taken_at: 1_790_000_000_000,
            path: "unused".into(),
            width: 1280,
            height: 720,
            attempts: 0,
        }
    }

    fn body(code: &str) -> String {
        format!(r#"{{"error":{{"code":"{code}","message":"x"}}}}"#)
    }

    async fn outcome_for(status: u16, body: String) -> Outcome {
        let server = MockServer::start(vec![(status, body)]).await;
        upload(&ApiClient::new(&server.root, "dev-1"), "tok", &row(), vec![0xFF, 0xD8, 0xFF, 0xD9]).await
    }

    #[tokio::test]
    async fn the_picture_is_sent_as_multipart_with_its_id_time_size_and_the_token() {
        let server = MockServer::start(vec![(201, r#"{"status":"stored"}"#.to_owned())]).await;

        let outcome = upload(&ApiClient::new(&server.root, "dev-1"), "secret", &row(), vec![0xFF, 0xD8, 0xFF, 0xD9]).await;

        assert_eq!(outcome, Outcome::Sent);
        let request = server.request(0);
        let lower = request.to_lowercase();
        assert!(lower.starts_with("post /api/v1/agent/screenshots"));
        assert!(lower.contains("authorization: bearer secret"));
        assert!(lower.contains("x-device-id: dev-1"));
        assert!(lower.contains("multipart/form-data"));
        assert!(request.contains("0198a5a0-0000-7000-8000-000000000001"));
        assert!(request.contains(r#"name="width""#) && request.contains("1280"));
        assert!(request.contains(r#"name="takenAt""#) && request.contains("2026-09-21T"), "an ISO time: {request}");
        assert!(request.contains(r#"name="image""#) && request.contains("image/jpeg"));
    }

    #[tokio::test]
    async fn stored_and_duplicate_both_mean_the_server_has_it() {
        assert_eq!(outcome_for(201, r#"{"status":"stored"}"#.into()).await, Outcome::Sent);
        assert_eq!(outcome_for(200, r#"{"status":"duplicate"}"#.into()).await, Outcome::Sent);
    }

    #[tokio::test]
    async fn answers_that_can_never_change_drop_the_local_copy() {
        assert_eq!(outcome_for(409, body("SCREENSHOTS_DISABLED")).await, Outcome::Dropped);
        assert_eq!(outcome_for(409, body("CONFLICT")).await, Outcome::Dropped);
        assert_eq!(outcome_for(422, body("VALIDATION_FAILED")).await, Outcome::Dropped);
    }

    #[tokio::test]
    async fn temporary_problems_keep_it_for_later() {
        assert_eq!(outcome_for(503, body("STORAGE_UNAVAILABLE")).await, Outcome::Retry);
        assert_eq!(outcome_for(500, body("SERVER_ERROR")).await, Outcome::Retry);
        assert_eq!(outcome_for(429, body("TOO_MANY_REQUESTS")).await, Outcome::Retry);
    }

    #[tokio::test]
    async fn a_bad_token_and_an_old_app_are_told_apart_and_keep_the_data() {
        assert_eq!(outcome_for(401, body("UNAUTHENTICATED")).await, Outcome::NeedsLogin);
        assert_eq!(outcome_for(426, body("UPGRADE_REQUIRED")).await, Outcome::UpgradeRequired);
    }

    #[tokio::test]
    async fn no_connection_is_offline() {
        let outcome = upload(&ApiClient::new(&dead_root().await, "d"), "t", &row(), vec![1, 2, 3]).await;

        assert_eq!(outcome, Outcome::Offline);
    }

    #[tokio::test]
    async fn the_own_list_and_a_picture_are_read_with_the_token() {
        let server = MockServer::start(vec![
            (200, r#"[{"id":"a","takenAt":"2026-09-26T05:00:00Z","width":1280,"height":720}]"#.to_owned()),
            (200, "JPEGBYTES".to_owned()),
            (403, body("FORBIDDEN")),
        ])
        .await;
        let api = ApiClient::new(&server.root, "d");

        let list = list_own(&api, "tok", "42", "2026-09-26").await.unwrap();
        let bytes = fetch_own(&api, "tok", "a", "thumb").await.unwrap();
        let refused = fetch_own(&api, "tok", "b", "image").await.unwrap_err();

        assert_eq!(list.len(), 1);
        assert_eq!(list[0].id, "a");
        assert_eq!(bytes, b"JPEGBYTES");
        assert!(matches!(refused, ApiError::Other { .. }));
        assert!(server.request(0).starts_with("GET /api/v1/employees/42/screenshots?day=2026-09-26"));
        assert!(server.request(1).starts_with("GET /api/v1/screenshots/a/thumb"));
    }
}
