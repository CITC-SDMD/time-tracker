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

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TaskTimeDto {
    /// `None` is the "No task" row: general, untagged time.
    pub id: Option<String>,
    pub title: String,
    pub tracked_seconds: i64,
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

/// Time per task today, most time first, "No task" always last. Unlike `build_app_list` this counts
/// every session type: a task's total is how long it was the one picked, idle time included, not just
/// active use. `known` is the caller's cached, currently-assigned tasks (id, title) -- every one of them
/// gets a row even at zero, so a freshly assigned task is visible before it has ever been worked. `open`
/// is the still-open session's elapsed ms, credited to whichever task id is currently picked (`None` for
/// no task); rows for a task id no longer in `known` (unassigned or archived since) are dropped, not
/// shown as an orphan -- the dashboard's task report is where that history lives.
pub fn build_task_list(rows: &[SessionRow], open: Option<(Option<&str>, i64)>, known: &[(String, String)]) -> Vec<TaskTimeDto> {
    let mut ms_by_task: HashMap<Option<String>, i64> = HashMap::new();
    for row in rows {
        *ms_by_task.entry(row.task_id.clone()).or_default() += row.duration_seconds.unwrap_or(0) * 1000;
    }
    if let Some((id, ms)) = open {
        *ms_by_task.entry(id.map(str::to_owned)).or_default() += ms;
    }

    let mut list: Vec<TaskTimeDto> = known
        .iter()
        .map(|(id, title)| TaskTimeDto {
            id: Some(id.clone()),
            title: title.clone(),
            tracked_seconds: ms_by_task.get(&Some(id.clone())).copied().unwrap_or(0) / 1000,
        })
        .collect();
    list.sort_by(|a, b| b.tracked_seconds.cmp(&a.tracked_seconds).then_with(|| a.title.cmp(&b.title)));
    list.push(TaskTimeDto {
        id: None,
        title: "No task".into(),
        tracked_seconds: ms_by_task.get(&None).copied().unwrap_or(0) / 1000,
    });
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
            task_id: None,
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

    fn tagged(kind: SessionType, task_id: Option<&str>, start: i64, end: Option<i64>) -> SessionRow {
        let mut r = row(kind, Some("Code"), None, start, end);
        r.task_id = task_id.map(str::to_owned);
        r
    }

    #[test]
    fn task_list_sums_active_and_idle_time_and_adds_the_open_session() {
        let known = [("1".to_owned(), "Budget report".to_owned())];
        let rows = [
            tagged(SessionType::Application, Some("1"), 0, Some(60_000)),
            tagged(SessionType::Idle, Some("1"), 60_000, Some(90_000)),
            tagged(SessionType::Application, None, 90_000, Some(120_000)),
        ];

        let list = build_task_list(&rows, Some((Some("1"), 30_000)), &known);

        assert_eq!(list.len(), 2, "the assigned task plus \"No task\"");
        assert_eq!(list[0], TaskTimeDto { id: Some("1".into()), title: "Budget report".into(), tracked_seconds: 120 });
        assert_eq!(list[1], TaskTimeDto { id: None, title: "No task".into(), tracked_seconds: 30 });
    }

    #[test]
    fn an_assigned_task_with_no_time_yet_still_appears_at_zero() {
        let known = [("1".to_owned(), "Fresh task".to_owned())];

        let list = build_task_list(&[], None, &known);

        assert_eq!(list, vec![
            TaskTimeDto { id: Some("1".into()), title: "Fresh task".into(), tracked_seconds: 0 },
            TaskTimeDto { id: None, title: "No task".into(), tracked_seconds: 0 },
        ]);
    }

    #[test]
    fn time_logged_against_a_task_no_longer_assigned_is_dropped_not_shown_as_an_orphan() {
        let rows = [tagged(SessionType::Application, Some("gone"), 0, Some(60_000))];

        let list = build_task_list(&rows, None, &[]);

        assert_eq!(list, vec![TaskTimeDto { id: None, title: "No task".into(), tracked_seconds: 0 }]);
    }

    #[test]
    fn the_open_sessions_time_credits_no_task_when_that_is_what_is_current() {
        let known = [("1".to_owned(), "Budget report".to_owned())];

        let list = build_task_list(&[], Some((None, 5_000)), &known);

        let no_task = list.iter().find(|t| t.id.is_none()).unwrap();
        assert_eq!(no_task.tracked_seconds, 5);
        assert_eq!(list.iter().find(|t| t.id.as_deref() == Some("1")).unwrap().tracked_seconds, 0);
    }
}
