//! Pure data types shared by `engine.rs`. No I/O, no DB, no platform code — keeps the
//! engine's control flow readable and these types independently testable.

use std::time::Instant;

use chrono::{DateTime, Utc};

use crate::platform::ForegroundApp;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TrackingState {
    NotTracking,
    Tracking,
    Paused,
    Away,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SessionKind {
    Active,
    Idle,
}

/// The currently-open session, mirrored in memory so a tick doesn't need a DB read to
/// know what's open.
#[derive(Debug, Clone)]
pub struct OpenSession {
    pub id: String,
    pub kind: SessionKind,
    pub app_name: Option<String>,
    pub process_name: Option<String>,
    pub window_title: Option<String>,
    pub idle_app_name: Option<String>,
    pub started_wall: DateTime<Utc>,
    /// When this session started counting on screen. Equals `started_wall`, except for an
    /// idle session noticed late: it is stored back-dated to the last input, but the live
    /// idle counter starts from the moment idle was noticed.
    pub display_from: DateTime<Utc>,
    pub started_mono: Instant,
    pub last_checkpoint_mono: Instant,
}

/// App-switch flicker absorption (docs §6.2 step 4): a newly-foreground app is held
/// here for one tick before it's promoted into a real session boundary. If it's gone by
/// the next tick, it's forgotten instead — this is what absorbs an Alt-Tab flicker.
#[derive(Debug, Clone)]
pub struct Candidate {
    pub app: ForegroundApp,
    pub first_seen_wall: DateTime<Utc>,
    pub first_seen_mono: Instant,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TitleMode {
    Full,
    AppOnly,
}

#[derive(Debug, Clone)]
pub struct OfficeSettings {
    pub idle_limit_seconds: u64,
    pub title_mode: TitleMode,
}

impl Default for OfficeSettings {
    /// Matches the server's `office_settings` defaults (docs §8) so the engine behaves
    /// sensibly before Phase 2's login/settings sync ever populates `app_state`.
    fn default() -> Self {
        Self {
            idle_limit_seconds: 300,
            title_mode: TitleMode::Full,
        }
    }
}
