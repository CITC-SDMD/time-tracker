//! Test doubles for the tracking engine's unit tests (`engine.rs`'s `tests` module).

use std::sync::Mutex;

use crate::platform::input::InputStats;
use crate::platform::{ActivityProvider, ForegroundApp};

pub struct FakeActivityProvider {
    current: Mutex<(Option<ForegroundApp>, u64)>,
    /// What the next `take_input_stats` hands over.
    stats: Mutex<Option<InputStats>>,
    /// The last `set_input_capture` the engine made.
    capture: Mutex<bool>,
}

impl FakeActivityProvider {
    pub fn new(initial: (Option<ForegroundApp>, u64)) -> Self {
        Self {
            current: Mutex::new(initial),
            stats: Mutex::new(None),
            capture: Mutex::new(true),
        }
    }

    /// The counts the next closed session will carry.
    pub fn set_stats(&self, stats: InputStats) {
        *self.stats.lock().unwrap() = Some(stats);
    }

    pub fn capture_on(&self) -> bool {
        *self.capture.lock().unwrap()
    }

    /// Changes what the provider reports starting from the next call -- tests call
    /// this between `engine.tick()`s to script app switches, idle transitions, etc.
    pub fn set(&self, app: Option<ForegroundApp>, idle_seconds: u64) {
        *self.current.lock().unwrap() = (app, idle_seconds);
    }
}

impl ActivityProvider for FakeActivityProvider {
    fn current_activity(&self) -> Option<ForegroundApp> {
        self.current.lock().unwrap().0.clone()
    }

    fn idle_seconds(&self) -> u64 {
        self.current.lock().unwrap().1
    }

    fn take_input_stats(&self) -> Option<crate::platform::input::InputStats> {
        self.stats.lock().unwrap().take()
    }

    fn set_input_capture(&self, on: bool) {
        *self.capture.lock().unwrap() = on;
    }
}

pub fn app(name: &str) -> ForegroundApp {
    ForegroundApp {
        app_name: name.to_string(),
        process_name: format!("{name}.exe"),
        window_title: name.to_string(),
    }
}
