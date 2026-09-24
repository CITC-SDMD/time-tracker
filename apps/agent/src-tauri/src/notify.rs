//! One-line notices: shown on screen (`notice` event) and, when the window is hidden or
//! not in front, also as a Windows notification.

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_notification::NotificationExt;

/// The on-screen banner already covers a window the person is looking at.
pub fn should_toast(window_visible: bool, window_focused: bool) -> bool {
    !(window_visible && window_focused)
}

pub fn notice(app: &AppHandle, message: &str) {
    let _ = app.emit("notice", message);
    let (visible, focused) = app
        .get_webview_window("main")
        .map(|w| (w.is_visible().unwrap_or(false), w.is_focused().unwrap_or(false)))
        .unwrap_or((false, false));
    if should_toast(visible, focused) {
        let _ = app
            .notification()
            .builder()
            .title("Time Tracker")
            .body(message)
            .show();
    }
}

#[cfg(test)]
mod tests {
    use super::should_toast;

    #[test]
    fn toasts_unless_the_window_is_visible_and_in_front() {
        assert!(!should_toast(true, true));
        assert!(should_toast(true, false));
        assert!(should_toast(false, false));
        assert!(should_toast(false, true));
    }
}
