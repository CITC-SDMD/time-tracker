//! Tray icon (colour by state), its menu, and the quit flow (docs §6.3).

use std::time::Duration;

use tauri::image::Image;
use tauri::menu::{Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};

use crate::commands::{stop_and_flush, AppState};
use crate::tracker::state::TrackingState;

const TRAY_ID: &str = "main";
const ICON_SIZE: u32 = 32;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TrayKind {
    Active,
    Idle,
    Paused,
    Off,
}

/// What the tray shows for one state. `state` is the status string the engine reports.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TrayView {
    pub kind: TrayKind,
    pub tooltip: &'static str,
    pub start: bool,
    pub pause: bool,
    pub resume: bool,
    pub stop: bool,
}

pub fn tray_view(state: &str, can_start: bool) -> TrayView {
    let (kind, tooltip) = match state {
        "active" => (TrayKind::Active, "Time Tracker: tracking"),
        "idle" => (TrayKind::Idle, "Time Tracker: idle"),
        "paused" => (TrayKind::Paused, "Time Tracker: paused"),
        "away" => (TrayKind::Off, "Time Tracker: away"),
        _ => (TrayKind::Off, "Time Tracker: not tracking"),
    };
    let tracking = matches!(state, "active" | "idle");
    TrayView {
        kind,
        tooltip,
        start: state == "not_tracking" && can_start,
        pause: tracking,
        resume: state == "paused",
        stop: tracking || matches!(state, "paused" | "away"),
    }
}

fn colour(kind: TrayKind) -> [u8; 3] {
    match kind {
        TrayKind::Active => [34, 170, 84],
        TrayKind::Idle => [240, 170, 20],
        TrayKind::Paused => [40, 120, 220],
        TrayKind::Off => [130, 136, 146],
    }
}

/// A filled circle with a soft edge, as raw RGBA.
pub fn paint_icon(kind: TrayKind) -> Vec<u8> {
    let [r, g, b] = colour(kind);
    let centre = (ICON_SIZE as f32 - 1.0) / 2.0;
    let radius = ICON_SIZE as f32 / 2.0 - 2.0;
    let mut rgba = Vec::with_capacity((ICON_SIZE * ICON_SIZE * 4) as usize);
    for y in 0..ICON_SIZE {
        for x in 0..ICON_SIZE {
            let d = ((x as f32 - centre).powi(2) + (y as f32 - centre).powi(2)).sqrt();
            let alpha = (radius + 0.5 - d).clamp(0.0, 1.0);
            rgba.extend_from_slice(&[r, g, b, (alpha * 255.0) as u8]);
        }
    }
    rgba
}

fn icon(kind: TrayKind) -> Image<'static> {
    Image::new_owned(paint_icon(kind), ICON_SIZE, ICON_SIZE)
}

struct Items {
    start: MenuItem<tauri::Wry>,
    pause: MenuItem<tauri::Wry>,
    resume: MenuItem<tauri::Wry>,
    stop: MenuItem<tauri::Wry>,
}

pub fn setup(app: &AppHandle) -> tauri::Result<()> {
    let open = MenuItem::with_id(app, "open", "Open", true, None::<&str>)?;
    let start = MenuItem::with_id(app, "start", "Start", false, None::<&str>)?;
    let pause = MenuItem::with_id(app, "pause", "Pause", false, None::<&str>)?;
    let resume = MenuItem::with_id(app, "resume", "Resume", false, None::<&str>)?;
    let stop = MenuItem::with_id(app, "stop", "Stop", false, None::<&str>)?;
    let quit = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
    let sep = PredefinedMenuItem::separator(app)?;
    let sep2 = PredefinedMenuItem::separator(app)?;
    let menu = Menu::with_items(app, &[&open, &sep, &start, &pause, &resume, &stop, &sep2, &quit])?;

    TrayIconBuilder::with_id(TRAY_ID)
        .icon(icon(TrayKind::Off))
        .tooltip("Time Tracker")
        .menu(&menu)
        .show_menu_on_left_click(false)
        .on_menu_event(|app, event| match event.id().as_ref() {
            "open" => crate::show_main_window(app),
            "start" => act(app, Action::Start),
            "pause" => act(app, Action::Pause),
            "resume" => act(app, Action::Resume),
            "stop" => act(app, Action::Stop),
            "quit" => quit_flow(app.clone()),
            _ => {}
        })
        .on_tray_icon_event(|tray, event| {
            if let TrayIconEvent::Click {
                button: MouseButton::Left,
                button_state: MouseButtonState::Up,
                ..
            } = event
            {
                crate::show_main_window(tray.app_handle());
            }
        })
        .build(app)?;

    app.manage(Items { start, pause, resume, stop });
    Ok(())
}

pub fn update(app: &AppHandle, view: &TrayView) {
    if let Some(tray) = app.tray_by_id(TRAY_ID) {
        let _ = tray.set_icon(Some(icon(view.kind)));
        let _ = tray.set_tooltip(Some(view.tooltip));
    }
    if let Some(items) = app.try_state::<Items>() {
        let _ = items.start.set_enabled(view.start);
        let _ = items.pause.set_enabled(view.pause);
        let _ = items.resume.set_enabled(view.resume);
        let _ = items.stop.set_enabled(view.stop);
    }
}

enum Action {
    Start,
    Pause,
    Resume,
    Stop,
}

fn act(app: &AppHandle, action: Action) {
    let state = app.state::<AppState>();
    let ready = crate::commands::can_track(&state.session.lock().unwrap_or_else(|e| e.into_inner()));
    let mut engine = state.engine.lock().unwrap_or_else(|e| e.into_inner());
    match action {
        Action::Start if ready => engine.start(),
        Action::Resume if ready => engine.resume(),
        Action::Pause => engine.pause(),
        Action::Stop => {
            engine.stop();
            drop(engine);
            state.sync.trigger();
        }
        _ => {}
    }
}

/// Asks first if something is being tracked, then stops, sends what it can for up to
/// 10 seconds, and exits.
fn quit_flow(app: AppHandle) {
    let tracking = {
        let state = app.state::<AppState>();
        let engine = state.engine.lock().unwrap_or_else(|e| e.into_inner());
        engine.state() != TrackingState::NotTracking
    };
    if !tracking {
        finish_quit(app);
        return;
    }
    let next = app.clone();
    app.dialog()
        .message("Stop tracking and quit?")
        .title("Time Tracker")
        .buttons(MessageDialogButtons::OkCancelCustom("Stop and quit".into(), "Cancel".into()))
        .show(move |confirmed| {
            if confirmed {
                finish_quit(next);
            }
        });
}

fn finish_quit(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        let state = app.state::<AppState>();
        let _ = stop_and_flush(&state, Duration::from_secs(10)).await;
        app.exit(0);
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn each_state_has_its_own_colour_and_tooltip() {
        let states = ["active", "idle", "paused", "not_tracking"];
        let icons: Vec<_> = states.iter().map(|s| paint_icon(tray_view(s, true).kind)).collect();
        for i in 0..icons.len() {
            for j in (i + 1)..icons.len() {
                assert_ne!(icons[i], icons[j], "{} and {} look the same", states[i], states[j]);
            }
        }
        assert_eq!(tray_view("active", true).tooltip, "Time Tracker: tracking");
    }

    #[test]
    fn icon_is_the_right_size_and_transparent_in_the_corners() {
        let rgba = paint_icon(TrayKind::Active);
        assert_eq!(rgba.len(), (ICON_SIZE * ICON_SIZE * 4) as usize);
        assert_eq!(rgba[3], 0, "corner pixel is transparent");
        let mid = ((ICON_SIZE / 2 * ICON_SIZE + ICON_SIZE / 2) * 4) as usize;
        assert_eq!(rgba[mid + 3], 255, "centre pixel is solid");
    }

    #[test]
    fn menu_items_follow_the_state() {
        let v = tray_view("not_tracking", true);
        assert_eq!((v.start, v.pause, v.resume, v.stop), (true, false, false, false));
        let v = tray_view("active", true);
        assert_eq!((v.start, v.pause, v.resume, v.stop), (false, true, false, true));
        let v = tray_view("idle", true);
        assert_eq!((v.start, v.pause, v.resume, v.stop), (false, true, false, true));
        let v = tray_view("paused", true);
        assert_eq!((v.start, v.pause, v.resume, v.stop), (false, false, true, true));
        let v = tray_view("away", true);
        assert_eq!((v.start, v.pause, v.resume, v.stop), (false, false, false, true));
    }

    #[test]
    fn start_needs_a_login_with_consent() {
        assert!(!tray_view("not_tracking", false).start);
        assert!(tray_view("not_tracking", true).start);
    }
}
