//! The tracking state machine and tick algorithm (docs/DEVELOPMENT_PLAN.md §6.2-§6.3).
//! Deliberately has **no Windows code** -- it only calls `platform::ActivityProvider`
//! and `tracker::clock::Clock`, both traits, so it's fully unit-testable with fakes.

use std::sync::Arc;
use std::time::{Duration, Instant};

use chrono::{DateTime, Utc};

use crate::db;
use crate::platform::{ActivityProvider, ForegroundApp, SystemEvent};
use crate::tracker::clock::Clock;
use crate::tracker::state::{
    Candidate, OfficeSettings, OpenSession, SessionKind, TitleMode, TrackingState,
};

const IDLE_HYSTERESIS_NONE: Duration = Duration::from_secs(30);
const CLOCK_JUMP_THRESHOLD: Duration = Duration::from_secs(60);
const CHUNK_DURATION: Duration = Duration::from_secs(600);

pub struct Engine<C: Clock> {
    clock: Arc<C>,
    provider: Arc<dyn ActivityProvider>,
    db: db::Db,
    state: TrackingState,
    open: Option<OpenSession>,
    candidate: Option<Candidate>,
    last_tick_mono: Option<Instant>,
    last_tick_wall: Option<DateTime<Utc>>,
    settings: OfficeSettings,
    user_id: String,
    device_id: String,
    /// When this tracking run began; survives pause/lock, cleared by stop. The server
    /// uses it to decide which PC "started later" under the one-PC rule (docs §10.1).
    tracking_started: Option<DateTime<Utc>>,
}

/// What the sync worker reports as the live status (docs §10.1 request `status`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StatusSnapshot {
    /// ACTIVE | IDLE | PAUSED | AWAY | NOT_TRACKING
    pub state: &'static str,
    pub current_app: Option<String>,
    pub idle_app_name: Option<String>,
    /// Start of the open session, when there is one.
    pub since: Option<DateTime<Utc>>,
    pub tracking_started_at: Option<DateTime<Utc>>,
}

impl<C: Clock> Engine<C> {
    pub fn new(
        clock: Arc<C>,
        provider: Arc<dyn ActivityProvider>,
        db: db::Db,
        settings: OfficeSettings,
        user_id: String,
        device_id: String,
    ) -> Self {
        Self {
            clock,
            provider,
            db,
            state: TrackingState::NotTracking,
            open: None,
            candidate: None,
            last_tick_mono: None,
            last_tick_wall: None,
            settings,
            user_id,
            device_id,
            tracking_started: None,
        }
    }

    pub fn status_snapshot(&self) -> StatusSnapshot {
        let (state, current_app, idle_app_name) = match (self.state, self.open.as_ref()) {
            (TrackingState::Tracking, Some(o)) if o.kind == SessionKind::Idle => {
                ("idle", None, o.idle_app_name.clone())
            }
            (TrackingState::Tracking, open) => ("active", open.and_then(|o| o.app_name.clone()), None),
            (TrackingState::Paused, _) => ("paused", None, None),
            (TrackingState::Away, _) => ("away", None, None),
            (TrackingState::NotTracking, _) => ("not_tracking", None, None),
        };
        StatusSnapshot {
            state,
            current_app,
            idle_app_name,
            since: self.open.as_ref().map(|o| o.started_wall),
            tracking_started_at: self.tracking_started,
        }
    }

    pub fn state(&self) -> TrackingState {
        self.state
    }

    pub fn db(&self) -> &db::Db {
        &self.db
    }

    pub fn user_id(&self) -> &str {
        &self.user_id
    }

    /// `(session_id, kind, started_at_ms)` for the currently open session, if any.
    pub fn open_session_info(&self) -> Option<(String, SessionKind, i64)> {
        self.open
            .as_ref()
            .map(|o| (o.id.clone(), o.kind, o.started_wall.timestamp_millis()))
    }

    /// `(app_name, window_title)` of the open ACTIVE session. The title is `None` when the
    /// office keeps app names only.
    pub fn open_activity(&self) -> Option<(Option<String>, Option<String>)> {
        self.open
            .as_ref()
            .filter(|o| o.kind == SessionKind::Active)
            .map(|o| (o.app_name.clone(), o.window_title.clone()))
    }

    /// `(active_ms, idle_ms)` of the open session as shown live on screen. An idle session
    /// is stored back-dated to the last input, but on screen the idle counter starts at
    /// one second when idle is noticed; the stretch before that still counts as active.
    /// Once the idle session closes, the saved totals take over (they include that stretch
    /// as idle).
    pub fn open_live_ms(&self) -> (i64, i64) {
        let Some(open) = &self.open else { return (0, 0) };
        let now = self.clock.now_wall();
        match open.kind {
            SessionKind::Active => ((now - open.started_wall).num_milliseconds().max(0), 0),
            SessionKind::Idle => {
                let before_noticed = (open.display_from - open.started_wall).num_milliseconds().max(0);
                let idle = (now - open.display_from).num_milliseconds().max(1000);
                (before_noticed, idle)
            }
        }
    }

    pub fn apply_settings(&mut self, settings: OfficeSettings) {
        self.settings = settings;
    }

    /// Switches which user new sessions are recorded under (login). Refused while a
    /// session is being tracked, so one session never straddles two users.
    pub fn set_user(&mut self, user_id: &str) -> bool {
        if self.user_id == user_id {
            return true; // same person logging back in mid-run (e.g. after "Please log in again")
        }
        if self.state != TrackingState::NotTracking {
            return false;
        }
        self.user_id = user_id.to_owned();
        let _ = self.db.set_app_state("current_user_id", user_id);
        true
    }

    pub fn start(&mut self) {
        if self.state != TrackingState::NotTracking {
            return;
        }
        self.begin_tracking();
    }

    pub fn resume(&mut self) {
        if self.state != TrackingState::Paused {
            return;
        }
        self.begin_tracking();
    }

    pub fn pause(&mut self) {
        if self.state != TrackingState::Tracking {
            return;
        }
        let now_wall = self.clock.now_wall();
        self.close_open(now_wall, false);
        self.state = TrackingState::Paused;
        self.candidate = None;
        self.last_tick_mono = None;
        self.last_tick_wall = None;
    }

    pub fn stop(&mut self) {
        if self.state == TrackingState::NotTracking {
            return;
        }
        let now_wall = self.clock.now_wall();
        self.close_open(now_wall, false);
        self.state = TrackingState::NotTracking;
        self.tracking_started = None;
        self.candidate = None;
        self.last_tick_mono = None;
        self.last_tick_wall = None;
        let _ = self.db.set_app_state("was_tracking", "no");
    }

    /// Routes a platform lock/unlock/sleep/wake/shutdown event per docs §6.3. Called
    /// from `lib.rs`'s event-listener thread; the explicit start/pause/resume/stop
    /// commands call their own methods directly instead of going through here.
    pub fn handle_system_event(&mut self, event: SystemEvent) {
        match event {
            SystemEvent::Lock | SystemEvent::Sleep => {
                if self.state == TrackingState::Tracking {
                    let now_wall = self.clock.now_wall();
                    self.close_open(now_wall, false);
                    self.state = TrackingState::Away;
                    self.candidate = None;
                    self.last_tick_mono = None;
                    self.last_tick_wall = None;
                }
            }
            SystemEvent::Unlock => {
                if self.state == TrackingState::Away {
                    self.begin_tracking();
                }
            }
            SystemEvent::Wake => {
                // Windows almost always locks on wake; the real resume happens on the
                // Unlock event that follows. No-op here per docs §6.3.
            }
            SystemEvent::Shutdown => {
                if self.state == TrackingState::Tracking {
                    let now_wall = self.clock.now_wall();
                    self.close_open(now_wall, false);
                    let _ = self.db.set_app_state("was_tracking", "yes");
                }
                self.state = TrackingState::NotTracking;
            }
        }
    }

    /// Crash recovery (task 6): any session left open by a previous run gets
    /// `ended_at = last_seen_at`. If the app was mid-tracking when it went away
    /// (`was_tracking = yes`), resumes automatically. Returns whether it auto-resumed,
    /// so the caller can surface a "Tracking resumed" notice. `allow_resume` is false when
    /// nobody is logged in: orphaned sessions are still closed, but tracking stays off.
    pub fn recover_on_startup(&mut self, allow_resume: bool) -> bool {
        if let Err(err) = self.db.close_all_open_sessions_at_last_seen() {
            tracing::error!(?err, "crash recovery: failed to close orphaned sessions");
        }
        let was_tracking = self.db.get_app_state("was_tracking").as_deref() == Some("yes");
        if was_tracking && allow_resume {
            self.begin_tracking();
            return true;
        }
        if was_tracking {
            let _ = self.db.set_app_state("was_tracking", "no");
        }
        false
    }

    fn begin_tracking(&mut self) {
        self.state = TrackingState::Tracking;
        self.candidate = None;
        let now_wall = self.clock.now_wall();
        let now_mono = self.clock.now_mono();
        self.tracking_started.get_or_insert(now_wall);
        self.open_for_current_activity(now_wall, now_mono);
        self.last_tick_mono = Some(now_mono);
        self.last_tick_wall = Some(now_wall);
        let _ = self.db.set_app_state("was_tracking", "yes");
    }

    /// Runs one full §6.2 tick, in order: gap check, clock check, idle check, app-switch
    /// check, chunk check, 30s checkpoint. No-op unless currently `Tracking`.
    pub fn tick(&mut self) {
        if self.state != TrackingState::Tracking {
            return;
        }
        let now_wall = self.clock.now_wall();
        let now_mono = self.clock.now_mono();

        // 1. Gap check: the loop itself stalled longer than a tick ever should.
        if let Some(last_mono) = self.last_tick_mono {
            if now_mono.saturating_duration_since(last_mono) > IDLE_HYSTERESIS_NONE {
                let last_wall = self.last_tick_wall.unwrap_or(now_wall);
                self.close_open(last_wall, false);
                self.open_for_current_activity(now_wall, now_mono);
                self.finish_tick(now_mono, now_wall);
                return;
            }
        }

        // 2. Clock check: wall time drifted from monotonic time -- the system clock
        // itself was changed (NTP, user, DST edge case beyond what UTC handles).
        if let (Some(last_mono), Some(last_wall)) = (self.last_tick_mono, self.last_tick_wall) {
            let mono_delta = now_mono.saturating_duration_since(last_mono);
            let wall_delta = (now_wall - last_wall).to_std().unwrap_or(Duration::ZERO);
            let drift = wall_delta
                .checked_sub(mono_delta)
                .or_else(|| mono_delta.checked_sub(wall_delta))
                .unwrap_or(Duration::ZERO);
            if drift > CLOCK_JUMP_THRESHOLD {
                if let Some(open) = &self.open {
                    let mono_since_start = now_mono.saturating_duration_since(open.started_mono);
                    let synthetic_end = open.started_wall
                        + chrono::Duration::from_std(mono_since_start).unwrap_or_default();
                    self.close_open(synthetic_end, true);
                }
                self.open_for_current_activity(now_wall, now_mono);
                self.finish_tick(now_mono, now_wall);
                return;
            }
        }

        let idle_seconds = self.provider.idle_seconds();
        let current_app = self.provider.current_activity();

        // 3. Idle check -- transitions happen at the last-input time, not "now".
        if let Some(open) = self.open.clone() {
            match open.kind {
                SessionKind::Active if idle_seconds >= self.settings.idle_limit_seconds => {
                    let (at_wall, at_mono) = self.last_input_time(now_wall, now_mono, idle_seconds);
                    self.close_open(at_wall, false);
                    let idle_app_name = current_app.as_ref().map(|a| a.app_name.clone());
                    self.open_idle(at_wall, at_mono, idle_app_name);
                    if let Some(idle) = &mut self.open {
                        idle.display_from = now_wall; // the on-screen idle counter starts now
                    }
                    self.candidate = None;
                }
                SessionKind::Idle if idle_seconds < self.settings.idle_limit_seconds => {
                    let (at_wall, at_mono) = self.last_input_time(now_wall, now_mono, idle_seconds);
                    self.close_open(at_wall, false);
                    self.open_active(at_wall, at_mono, current_app.as_ref());
                    self.candidate = None;
                }
                _ => {}
            }
        }

        // 4. App switch check (ACTIVE only) -- a new foreground app must survive one
        // full extra tick before it's treated as a real switch (absorbs Alt-Tab flicker).
        if let Some(open) = self.open.clone() {
            if open.kind == SessionKind::Active {
                match &current_app {
                    Some(current) if is_different_app(current, &open) => {
                        let promote = self
                            .candidate
                            .as_ref()
                            .is_some_and(|c| same_app(&c.app, current));
                        if promote {
                            let candidate = self.candidate.take().unwrap();
                            self.close_open(candidate.first_seen_wall, false);
                            self.open_active(
                                candidate.first_seen_wall,
                                candidate.first_seen_mono,
                                Some(&candidate.app),
                            );
                        } else {
                            self.candidate = Some(Candidate {
                                app: current.clone(),
                                first_seen_wall: now_wall,
                                first_seen_mono: now_mono,
                            });
                        }
                    }
                    Some(current) => {
                        // Same app as the open session: any pending candidate was a
                        // flicker, forget it. A title-only change updates in place.
                        self.candidate = None;
                        if Some(current.window_title.clone()) != open.window_title {
                            self.update_open_title(current.window_title.clone());
                        }
                    }
                    None => {}
                }
            }
        }

        // 5. Chunk check: split an open session once it hits 10 minutes.
        if let Some(open) = self.open.clone() {
            if now_mono.saturating_duration_since(open.started_mono) >= CHUNK_DURATION {
                match open.kind {
                    SessionKind::Active => {
                        let (at_wall, at_mono) =
                            self.last_input_time(now_wall, now_mono, idle_seconds);
                        self.close_open(at_wall, false);
                        self.open_active(at_wall, at_mono, current_app.as_ref());
                    }
                    SessionKind::Idle => {
                        let idle_app_name = open.idle_app_name.clone();
                        self.close_open(now_wall, false);
                        self.open_idle(now_wall, now_mono, idle_app_name);
                    }
                }
            }
        }

        // 6. Checkpoint `last_seen_at` every 30s, for crash recovery.
        if let Some(open) = &mut self.open {
            if now_mono.saturating_duration_since(open.last_checkpoint_mono) >= IDLE_HYSTERESIS_NONE
            {
                let _ = self.db.touch_last_seen(&open.id, now_wall.timestamp_millis());
                open.last_checkpoint_mono = now_mono;
            }
        }

        self.finish_tick(now_mono, now_wall);
    }

    fn finish_tick(&mut self, now_mono: Instant, now_wall: DateTime<Utc>) {
        self.last_tick_mono = Some(now_mono);
        self.last_tick_wall = Some(now_wall);
    }

    /// The moment input actually stopped (idle transitions) or resumed (idle exit),
    /// i.e. "now minus however long the current idle reading covers".
    fn last_input_time(
        &self,
        now_wall: DateTime<Utc>,
        now_mono: Instant,
        idle_seconds: u64,
    ) -> (DateTime<Utc>, Instant) {
        let at_wall = now_wall - chrono::Duration::seconds(idle_seconds as i64);
        let at_mono = now_mono
            .checked_sub(Duration::from_secs(idle_seconds))
            .unwrap_or(now_mono);
        (at_wall, at_mono)
    }

    fn open_for_current_activity(&mut self, at_wall: DateTime<Utc>, at_mono: Instant) {
        let idle_seconds = self.provider.idle_seconds();
        if idle_seconds >= self.settings.idle_limit_seconds {
            let idle_app_name = self.provider.current_activity().map(|a| a.app_name);
            self.open_idle(at_wall, at_mono, idle_app_name);
        } else {
            let app = self.provider.current_activity();
            self.open_active(at_wall, at_mono, app.as_ref());
        }
    }

    fn open_active(&mut self, at_wall: DateTime<Utc>, at_mono: Instant, app: Option<&ForegroundApp>) {
        let id = uuid::Uuid::now_v7().to_string();
        let window_title = match self.settings.title_mode {
            TitleMode::Full => app.map(|a| a.window_title.clone()),
            TitleMode::AppOnly => None,
        };
        let new = db::NewSession {
            id: id.clone(),
            user_id: self.user_id.clone(),
            device_id: self.device_id.clone(),
            session_type: db::SessionType::Application,
            app_name: app.map(|a| a.app_name.clone()),
            process_name: app.map(|a| a.process_name.clone()),
            window_title: window_title.clone(),
            idle_app_name: None,
            started_at: at_wall.timestamp_millis(),
            last_seen_at: at_wall.timestamp_millis(),
        };
        if let Err(err) = self.db.open_session(&new) {
            tracing::error!(?err, "failed to open ACTIVE session");
            return;
        }
        self.open = Some(OpenSession {
            id,
            kind: SessionKind::Active,
            app_name: app.map(|a| a.app_name.clone()),
            process_name: app.map(|a| a.process_name.clone()),
            window_title,
            idle_app_name: None,
            started_wall: at_wall,
            display_from: at_wall,
            started_mono: at_mono,
            last_checkpoint_mono: at_mono,
        });
    }

    fn open_idle(&mut self, at_wall: DateTime<Utc>, at_mono: Instant, idle_app_name: Option<String>) {
        let id = uuid::Uuid::now_v7().to_string();
        let new = db::NewSession {
            id: id.clone(),
            user_id: self.user_id.clone(),
            device_id: self.device_id.clone(),
            session_type: db::SessionType::Idle,
            app_name: None,
            process_name: None,
            window_title: None,
            idle_app_name: idle_app_name.clone(),
            started_at: at_wall.timestamp_millis(),
            last_seen_at: at_wall.timestamp_millis(),
        };
        if let Err(err) = self.db.open_session(&new) {
            tracing::error!(?err, "failed to open IDLE session");
            return;
        }
        self.open = Some(OpenSession {
            id,
            kind: SessionKind::Idle,
            app_name: None,
            process_name: None,
            window_title: None,
            idle_app_name,
            started_wall: at_wall,
            display_from: at_wall,
            started_mono: at_mono,
            last_checkpoint_mono: at_mono,
        });
    }

    fn close_open(&mut self, at_wall: DateTime<Utc>, clock_changed: bool) {
        if let Some(open) = self.open.take() {
            if let Err(err) = self
                .db
                .close_session(&open.id, at_wall.timestamp_millis(), clock_changed)
            {
                tracing::error!(?err, "failed to close session");
            }
        }
    }

    fn update_open_title(&mut self, window_title: String) {
        if self.settings.title_mode != TitleMode::Full {
            return;
        }
        if let Some(open) = &mut self.open {
            open.window_title = Some(window_title.clone());
            let _ = self.db.update_session_title(&open.id, Some(&window_title));
        }
    }
}

fn is_different_app(current: &ForegroundApp, open: &OpenSession) -> bool {
    Some(&current.app_name) != open.app_name.as_ref()
        || Some(&current.process_name) != open.process_name.as_ref()
}

fn same_app(a: &ForegroundApp, b: &ForegroundApp) -> bool {
    a.app_name == b.app_name && a.process_name == b.process_name
}

#[cfg(test)]
mod tests {
    use std::sync::Arc;
    use std::time::Duration;

    use chrono::Utc;

    use super::*;
    use crate::db::{Db, SessionType};
    use crate::tracker::clock::FakeClock;
    use crate::tracker::state::OfficeSettings;
    use crate::tracker::testing::{app, FakeActivityProvider};

    fn engine_with(
        idle_limit_seconds: u64,
        initial: (Option<ForegroundApp>, u64),
    ) -> (Engine<FakeClock>, Arc<FakeClock>, Arc<FakeActivityProvider>) {
        let clock = Arc::new(FakeClock::new(Utc::now()));
        let provider = Arc::new(FakeActivityProvider::new(initial));
        let db = Db::open_in_memory_for_test().unwrap();
        let settings = OfficeSettings {
            idle_limit_seconds,
            title_mode: TitleMode::Full,
        };
        let engine = Engine::new(
            clock.clone(),
            provider.clone(),
            db,
            settings,
            "test-user".into(),
            "test-device".into(),
        );
        (engine, clock, provider)
    }

    fn all_sessions<C: Clock>(engine: &Engine<C>) -> Vec<db::SessionRow> {
        engine.db().sessions_for_range("test-user", 0, i64::MAX).unwrap()
    }

    #[test]
    fn app_switch_creates_two_sessions() {
        let (mut engine, clock, provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.start();
        for _ in 0..3 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }

        provider.set(Some(app("Chrome")), 0);
        clock.advance(Duration::from_secs(2));
        engine.tick(); // candidate seen
        clock.advance(Duration::from_secs(2));
        engine.tick(); // still Chrome -> promoted

        engine.stop();

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 2);
        assert_eq!(sessions[0].app_name.as_deref(), Some("VSCode"));
        assert_eq!(sessions[1].app_name.as_deref(), Some("Chrome"));
        assert_eq!(sessions[0].ended_at, Some(sessions[1].started_at));
    }

    #[test]
    fn single_tick_flicker_is_ignored() {
        let (mut engine, clock, provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.start();
        clock.advance(Duration::from_secs(2));
        engine.tick();

        provider.set(Some(app("Chrome")), 0);
        clock.advance(Duration::from_secs(2));
        engine.tick(); // candidate seen

        provider.set(Some(app("VSCode")), 0); // back before it was promoted
        clock.advance(Duration::from_secs(2));
        engine.tick();
        clock.advance(Duration::from_secs(60));
        engine.tick();

        engine.stop();

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 1);
        assert_eq!(sessions[0].app_name.as_deref(), Some("VSCode"));
    }

    #[test]
    fn idle_enter_and_exit_happen_at_last_input_time() {
        let (mut engine, clock, provider) = engine_with(5, (Some(app("Chrome")), 0));
        engine.start();
        // 10s of real active time before idle begins, so backdating the idle start by
        // 8s below still lands after the session's actual start.
        for _ in 0..5 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }

        provider.set(Some(app("Chrome")), 8);
        clock.advance(Duration::from_secs(2));
        let expected_idle_start = clock.now_wall() - chrono::Duration::seconds(8);
        engine.tick();

        // Two rows now: the closed ACTIVE session, and the IDLE session just opened
        // (still open -- `ended_at` is only set once the return-from-idle closes it).
        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 2);
        assert_eq!(sessions[0].session_type, SessionType::Application);
        let ended_at = sessions[0].ended_at.unwrap();
        assert!((ended_at - expected_idle_start.timestamp_millis()).abs() < 1000);

        provider.set(Some(app("Chrome")), 0);
        clock.advance(Duration::from_secs(2));
        engine.tick();
        // A little more real time before stopping, so the reopened session has a
        // non-zero (and thus saved, not deleted) duration.
        clock.advance(Duration::from_secs(5));
        engine.stop();

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 3);
        assert_eq!(sessions[1].session_type, SessionType::Idle);
        assert_eq!(sessions[1].idle_app_name.as_deref(), Some("Chrome"));
        assert_eq!(sessions[2].session_type, SessionType::Application);
    }

    #[test]
    fn live_idle_counter_starts_at_one_second_when_idle_is_noticed() {
        let (mut engine, clock, provider) = engine_with(5, (Some(app("Chrome")), 0));
        engine.start();
        for _ in 0..5 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }
        let (active_ms, idle_ms) = engine.open_live_ms();
        assert!(active_ms >= 10_000);
        assert_eq!(idle_ms, 0);

        // Idle is noticed 8s after the last input: stored back-dated, shown from 1s.
        provider.set(Some(app("Chrome")), 8);
        clock.advance(Duration::from_secs(2));
        engine.tick();
        let (before_noticed_ms, idle_ms) = engine.open_live_ms();
        assert_eq!(idle_ms, 1000);
        assert_eq!(before_noticed_ms, 8000);

        clock.advance(Duration::from_secs(3));
        assert_eq!(engine.open_live_ms().1, 3000);
    }

    #[test]
    fn ten_minute_chunking_active() {
        let (mut engine, clock, _provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.start();
        for _ in 0..300 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }
        for _ in 0..2 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }
        engine.stop();

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 2);
        assert_eq!(sessions[0].duration_seconds, Some(600));
        assert_eq!(sessions[0].ended_at, Some(sessions[1].started_at));
    }

    #[test]
    fn ten_minute_chunking_idle() {
        let (mut engine, clock, _provider) = engine_with(5, (Some(app("Zoom")), 1000));
        engine.start(); // idle_seconds already above the limit -> opens IDLE immediately
        for _ in 0..300 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }
        for _ in 0..2 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }
        engine.stop();

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 2);
        assert_eq!(sessions[0].session_type, SessionType::Idle);
        assert_eq!(sessions[0].duration_seconds, Some(600));
        assert_eq!(sessions[0].ended_at, Some(sessions[1].started_at));
    }

    #[test]
    fn pause_resume_stop_produce_a_gap() {
        let (mut engine, clock, _provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.start();
        // Real, continuous 2s ticks -- a single 60s jump would look like a stall (the
        // gap check exists precisely to catch that case, tested separately below).
        for _ in 0..30 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }
        engine.pause();
        assert_eq!(engine.state(), TrackingState::Paused);

        clock.advance(Duration::from_secs(120));

        engine.resume();
        assert_eq!(engine.state(), TrackingState::Tracking);
        for _ in 0..30 {
            clock.advance(Duration::from_secs(2));
            engine.tick();
        }
        engine.stop();
        assert_eq!(engine.state(), TrackingState::NotTracking);

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 2);
        assert_eq!(sessions[0].duration_seconds, Some(60));
        assert_eq!(sessions[1].duration_seconds, Some(60));
        // The 120s paused gap isn't covered by either session.
        assert_eq!(
            sessions[1].started_at - sessions[0].ended_at.unwrap(),
            120_000
        );
    }

    #[test]
    fn gap_over_30s_closes_at_the_last_tick() {
        let (mut engine, clock, _provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.start();
        clock.advance(Duration::from_secs(2));
        engine.tick();
        let last_tick_wall = clock.now_wall();

        // Simulate the loop itself stalling for a while (no explicit sleep event fired).
        clock.advance(Duration::from_secs(45));
        engine.tick();
        // A little more real time before stopping, so the reopened session has a
        // non-zero (and thus saved, not deleted) duration.
        clock.advance(Duration::from_secs(5));
        engine.stop();

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 2);
        assert_eq!(sessions[0].ended_at, Some(last_tick_wall.timestamp_millis()));
    }

    #[test]
    fn wall_clock_jump_sets_clock_changed_and_keeps_duration_correct() {
        let (mut engine, clock, _provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.start();
        clock.advance(Duration::from_secs(2));
        engine.tick();

        // Wall clock jumps forward an hour with no real (monotonic) time passing.
        clock.jump_wall_only(chrono::Duration::hours(1));
        clock.advance(Duration::from_secs(2));
        engine.tick();
        // A little more real time before stopping, so the reopened session has a
        // non-zero (and thus saved, not deleted) duration.
        clock.advance(Duration::from_secs(5));
        engine.stop();

        let sessions = all_sessions(&engine);
        assert_eq!(sessions.len(), 2);
        // Duration comes from the monotonic clock, not the wall jump -- nowhere near an hour.
        assert!(sessions[0].duration_seconds.unwrap() < 10);
    }

    #[test]
    fn crash_recovery_closes_orphan_and_auto_resumes_when_was_tracking() {
        let db = Db::open_in_memory_for_test().unwrap();
        let last_seen = Utc::now().timestamp_millis();
        db.open_session(&db::NewSession {
            id: uuid::Uuid::now_v7().to_string(),
            user_id: "test-user".into(),
            device_id: "test-device".into(),
            session_type: SessionType::Application,
            app_name: Some("VSCode".into()),
            process_name: Some("Code.exe".into()),
            window_title: None,
            idle_app_name: None,
            started_at: last_seen - 60_000,
            last_seen_at: last_seen,
        })
        .unwrap();
        db.set_app_state("was_tracking", "yes").unwrap();

        let clock = Arc::new(FakeClock::new(Utc::now()));
        let provider = Arc::new(FakeActivityProvider::new((Some(app("VSCode")), 0)));
        let settings = OfficeSettings {
            idle_limit_seconds: 300,
            title_mode: TitleMode::Full,
        };
        let mut engine = Engine::new(
            clock,
            provider,
            db,
            settings,
            "test-user".into(),
            "test-device".into(),
        );

        let auto_resumed = engine.recover_on_startup(true);

        assert!(auto_resumed);
        assert_eq!(engine.state(), TrackingState::Tracking);
        let sessions = all_sessions(&engine);
        // The orphaned session was closed at last_seen_at, and a fresh one opened.
        assert_eq!(sessions.len(), 2);
        assert_eq!(sessions[0].ended_at, Some(last_seen));
    }

    #[test]
    fn logged_out_startup_closes_orphans_but_does_not_resume() {
        let (mut engine, _clock, _provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.db().set_app_state("was_tracking", "yes").unwrap();

        assert!(!engine.recover_on_startup(false));

        assert_eq!(engine.state(), TrackingState::NotTracking);
        assert_eq!(engine.db().get_app_state("was_tracking").as_deref(), Some("no"));
    }

    #[test]
    fn status_snapshot_follows_the_state_machine() {
        let (mut engine, clock, provider) = engine_with(300, (Some(app("VSCode")), 0));
        let snapshot = engine.status_snapshot();
        assert_eq!(snapshot.state, "not_tracking");
        assert!(snapshot.tracking_started_at.is_none());

        engine.start();
        let snapshot = engine.status_snapshot();
        assert_eq!(snapshot.state, "active");
        assert_eq!(snapshot.current_app.as_deref(), Some("VSCode"));
        let started = snapshot.tracking_started_at.expect("set when tracking begins");

        engine.pause();
        assert_eq!(engine.status_snapshot().state, "paused");
        clock.advance(Duration::from_secs(5));
        provider.set(Some(app("VSCode")), 0);
        engine.resume();
        // Pausing does not restart "tracking started at": the one-PC rule compares run starts.
        assert_eq!(engine.status_snapshot().tracking_started_at, Some(started));

        engine.stop();
        assert_eq!(engine.status_snapshot().state, "not_tracking");
        assert!(engine.status_snapshot().tracking_started_at.is_none());
    }

    #[test]
    fn user_can_only_change_while_not_tracking() {
        let (mut engine, _clock, _provider) = engine_with(300, (Some(app("VSCode")), 0));

        assert!(engine.set_user("42"));
        assert_eq!(engine.user_id(), "42");
        assert_eq!(engine.db().get_app_state("current_user_id").as_deref(), Some("42"));

        engine.start();
        assert!(!engine.set_user("99"));
        assert_eq!(engine.user_id(), "42");
    }

    #[test]
    fn sub_one_second_session_is_deleted_not_queued() {
        let (mut engine, clock, provider) = engine_with(300, (Some(app("VSCode")), 0));
        engine.start();

        // Force an immediate gap-triggered close on the very next tick, half a second
        // later -- shorter than the 1-second minimum.
        provider.set(Some(app("VSCode")), 0);
        clock.advance(Duration::from_millis(500));
        engine.close_open(clock.now_wall(), false);

        let sessions = all_sessions(&engine);
        assert!(sessions.is_empty());
    }

    #[test]
    fn totals_tracked_equals_active_plus_idle() {
        let (mut engine, clock, provider) = engine_with(5, (Some(app("Chrome")), 0));
        engine.start();
        clock.advance(Duration::from_secs(10));
        engine.tick();

        provider.set(Some(app("Chrome")), 20);
        clock.advance(Duration::from_secs(2));
        engine.tick();

        provider.set(Some(app("Chrome")), 0);
        clock.advance(Duration::from_secs(15));
        engine.tick();
        engine.stop();

        let sessions = all_sessions(&engine);
        let active: i64 = sessions
            .iter()
            .filter(|s| s.session_type == SessionType::Application)
            .filter_map(|s| s.duration_seconds)
            .sum();
        let idle: i64 = sessions
            .iter()
            .filter(|s| s.session_type == SessionType::Idle)
            .filter_map(|s| s.duration_seconds)
            .sum();
        let tracked: i64 = sessions.iter().filter_map(|s| s.duration_seconds).sum();
        assert_eq!(tracked, active + idle);
    }
}
