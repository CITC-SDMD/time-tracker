//! OS-specific activity detection. The rest of the app only uses the types
//! and trait in this file, so macOS/Linux can be added later as new modules.

use std::sync::mpsc::Sender;

use serde::Serialize;

pub mod environment;
pub mod input;
pub mod macro_tools;

#[cfg(windows)]
mod win;
#[cfg(windows)]
use win as imp;

#[cfg(not(windows))]
mod fallback;
#[cfg(not(windows))]
use fallback as imp;

/// The window that currently has keyboard focus.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ForegroundApp {
    /// Friendly name, e.g. "Visual Studio Code".
    pub app_name: String,
    /// Executable name, e.g. "Code.exe". Empty when it can't be read.
    pub process_name: String,
    pub window_title: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "SCREAMING_SNAKE_CASE")]
pub enum SystemEvent {
    Lock,
    Unlock,
    Sleep,
    Wake,
    Shutdown,
}

pub trait ActivityProvider: Send + Sync {
    /// `None` only when the platform can't report anything at all.
    fn current_activity(&self) -> Option<ForegroundApp>;
    /// Seconds since the last keyboard or mouse input.
    fn idle_seconds(&self) -> u64;
    /// The counts of input since the last call (the activity check); `None` where the platform cannot tell.
    fn take_input_stats(&self) -> Option<input::InputStats> {
        None
    }
    /// Switches the counting on or off (the server can turn the detection off for a person).
    fn set_input_capture(&self, _on: bool) {}
}

pub fn provider() -> Box<dyn ActivityProvider> {
    imp::provider()
}

/// Names of the running processes, for the known-macro-program check (see `macro_tools`).
#[allow(dead_code)]
pub(crate) fn running_process_names() -> Vec<String> {
    imp::running_process_names()
}

/// Starts a background thread that sends lock/unlock/sleep/wake/shutdown events.
pub fn start_system_events(tx: Sender<SystemEvent>) {
    imp::start_system_events(tx)
}
