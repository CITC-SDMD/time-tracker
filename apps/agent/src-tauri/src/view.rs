//! Display-ready shapes for the home screen, built from saved sessions. Pure functions
//! so they can be tested without a database or a window.

use std::collections::HashMap;

use serde::Serialize;

use crate::db::{SessionRow, SessionType};

/// Chunks of one long stretch are saved 10 minutes apart with no gap; anything closer
/// than this between two same-app rows counts as one block.
const MERGE_GAP_MS: i64 = 5_000;

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SegmentDto {
    /// `ACTIVE` or `IDLE`
    pub kind: &'static str,
    /// The app name, or "Idle (in <app>)".
    pub label: String,
    pub started_at: i64,
    pub ended_at: i64,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AppTimeDto {
    pub name: String,
    pub seconds: i64,
}

fn label_for(row: &SessionRow) -> (&'static str, String) {
    match row.session_type {
        SessionType::Application => (
            "active",
            row.app_name.clone().unwrap_or_else(|| "Unknown app".to_owned()),
        ),
        SessionType::Idle => (
            "idle",
            match &row.idle_app_name {
                Some(app) => format!("Idle (in {app})"),
                None => "Idle".to_owned(),
            },
        ),
    }
}

/// Rows must be ordered by start time. A row still open ends "now".
pub fn build_timeline(rows: &[SessionRow], now_ms: i64) -> Vec<SegmentDto> {
    let mut segments: Vec<SegmentDto> = Vec::new();
    for row in rows {
        let ended_at = row
            .ended_at
            .unwrap_or(now_ms)
            .max(row.started_at);
        let (kind, label) = label_for(row);
        if let Some(last) = segments.last_mut() {
            if last.kind == kind && last.label == label && row.started_at - last.ended_at <= MERGE_GAP_MS {
                last.ended_at = last.ended_at.max(ended_at);
                continue;
            }
        }
        segments.push(SegmentDto {
            kind,
            label,
            started_at: row.started_at,
            ended_at,
        });
    }
    segments
}

/// Time per app from saved ACTIVE sessions, plus the still-open one (`open_app`,
/// milliseconds counted so far), most time first.
pub fn build_app_list(rows: &[SessionRow], open: Option<(&str, i64)>) -> Vec<AppTimeDto> {
    let mut ms_by_app: HashMap<String, i64> = HashMap::new();
    for row in rows.iter().filter(|r| r.session_type == SessionType::Application) {
        let name = row.app_name.clone().unwrap_or_else(|| "Unknown app".to_owned());
        *ms_by_app.entry(name).or_default() += row.duration_seconds.unwrap_or(0) * 1000;
    }
    if let Some((name, ms)) = open {
        *ms_by_app.entry(name.to_owned()).or_default() += ms;
    }
    let mut list: Vec<AppTimeDto> = ms_by_app
        .into_iter()
        .map(|(name, ms)| AppTimeDto { name, seconds: ms / 1000 })
        .filter(|a| a.seconds > 0)
        .collect();
    list.sort_by(|a, b| b.seconds.cmp(&a.seconds).then_with(|| a.name.cmp(&b.name)));
    list
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(kind: SessionType, app: Option<&str>, idle_app: Option<&str>, start: i64, end: Option<i64>) -> SessionRow {
        SessionRow {
            id: format!("{start}"),
            session_type: kind,
            app_name: app.map(str::to_owned),
            idle_app_name: idle_app.map(str::to_owned),
            started_at: start,
            ended_at: end,
            duration_seconds: end.map(|e| (e - start) / 1000),
            sync_status: "PENDING".into(),
        }
    }

    fn active(app: &str, start: i64, end: Option<i64>) -> SessionRow {
        row(SessionType::Application, Some(app), None, start, end)
    }

    #[test]
    fn adjacent_chunks_of_the_same_app_become_one_block() {
        let rows = [active("Code", 0, Some(600_000)), active("Code", 600_000, Some(900_000))];
        let t = build_timeline(&rows, 1_000_000);
        assert_eq!(t.len(), 1);
        assert_eq!((t[0].started_at, t[0].ended_at), (0, 900_000));
    }

    #[test]
    fn a_gap_or_another_app_starts_a_new_block() {
        let rows = [
            active("Code", 0, Some(60_000)),
            active("Code", 120_000, Some(180_000)),
            active("Chrome", 180_000, Some(240_000)),
        ];
        let t = build_timeline(&rows, 1_000_000);
        assert_eq!(t.iter().map(|s| s.label.as_str()).collect::<Vec<_>>(), ["Code", "Code", "Chrome"]);
    }

    #[test]
    fn idle_blocks_are_labelled_with_the_app_that_was_on_screen() {
        let rows = [
            row(SessionType::Idle, None, Some("Zoom"), 0, Some(60_000)),
            row(SessionType::Idle, None, None, 60_000, Some(90_000)),
        ];
        let t = build_timeline(&rows, 1_000_000);
        assert_eq!(t[0].label, "Idle (in Zoom)");
        assert_eq!(t[0].kind, "idle");
        assert_eq!(t[1].label, "Idle");
    }

    #[test]
    fn an_open_session_ends_now() {
        let rows = [active("Code", 1_000, None)];
        let t = build_timeline(&rows, 9_000);
        assert_eq!(t[0].ended_at, 9_000);
    }

    #[test]
    fn app_list_sums_per_app_adds_the_open_one_and_sorts() {
        let rows = [
            active("Chrome", 0, Some(60_000)),
            active("Code", 60_000, Some(360_000)),
            active("Chrome", 360_000, Some(420_000)),
            row(SessionType::Idle, None, Some("Zoom"), 420_000, Some(900_000)),
        ];
        let list = build_app_list(&rows, Some(("Code", 30_000)));
        assert_eq!(
            list,
            vec![
                AppTimeDto { name: "Code".into(), seconds: 330 },
                AppTimeDto { name: "Chrome".into(), seconds: 120 },
            ]
        );
    }

    #[test]
    fn app_list_leaves_out_apps_with_no_whole_second() {
        let rows = [active("Blip", 0, Some(400))];
        assert!(build_app_list(&rows, None).is_empty());
    }
}
