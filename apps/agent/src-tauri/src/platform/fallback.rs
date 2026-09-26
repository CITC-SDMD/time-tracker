//! Placeholder for platforms we don't support yet. It lets the app build and
//! open on macOS/Linux for UI work, but reports no activity.

use std::sync::mpsc::Sender;

use super::{ActivityProvider, ForegroundApp, SystemEvent};

struct UnsupportedProvider;

impl ActivityProvider for UnsupportedProvider {
    fn current_activity(&self) -> Option<ForegroundApp> {
        None
    }

    fn idle_seconds(&self) -> u64 {
        0
    }
}

pub fn provider() -> Box<dyn ActivityProvider> {
    Box::new(UnsupportedProvider)
}

pub fn start_system_events(_tx: Sender<SystemEvent>) {}

/// Nothing can be read here: the computer counts as a physical one.
pub fn environment_facts() -> super::environment::Facts {
    super::environment::Facts::default()
}
