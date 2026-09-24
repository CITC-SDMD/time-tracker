//! Test doubles for the tracking engine's unit tests (`engine.rs`'s `tests` module).

use std::sync::Mutex;

use crate::platform::{ActivityProvider, ForegroundApp};

pub struct FakeActivityProvider {
    current: Mutex<(Option<ForegroundApp>, u64)>,
}

impl FakeActivityProvider {
    pub fn new(initial: (Option<ForegroundApp>, u64)) -> Self {
        Self {
            current: Mutex::new(initial),
        }
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
}

pub fn app(name: &str) -> ForegroundApp {
    ForegroundApp {
        app_name: name.to_string(),
        process_name: format!("{name}.exe"),
        window_title: name.to_string(),
    }
}
