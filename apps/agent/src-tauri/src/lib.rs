mod api;
mod auth;
mod commands;
mod db;
mod logging;
mod platform;
mod sync;
#[cfg(test)]
mod testutil;
mod tracker;

use std::sync::{Arc, Mutex};

use tauri::menu::{Menu, MenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Emitter, Manager, WindowEvent};

use tracker::clock::SystemClock;
use tracker::engine::Engine;

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        // Must be the first plugin: a second launch just shows the running app.
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            show_main_window(app);
        }))
        .invoke_handler(tauri::generate_handler![
            commands::login,
            commands::logout,
            commands::get_session,
            commands::refresh_me,
            commands::accept_consent,
            commands::get_sync_status,
            commands::start_tracking,
            commands::pause_tracking,
            commands::resume_tracking,
            commands::stop_tracking,
            commands::get_tracking_state,
            commands::get_today_summary,
            commands::get_today_timeline,
            commands::get_today_sessions_debug,
        ])
        .setup(|app| {
            let log_guard = logging::init(app.handle())?;

            let app_data_dir = app.path().app_data_dir()?;
            std::fs::create_dir_all(&app_data_dir)?;
            let db_path = app_data_dir.join("tracker.db");
            let db = db::Db::open(&db_path)?;

            let settings = tracker::load_office_settings(&db);
            let user_id = db
                .get_app_state("current_user_id")
                .expect("seeded by db::Db::open on first run");
            let device_id = db
                .get_app_state("device_id")
                .expect("seeded by db::Db::open on first run");

            let api = api::ApiClient::from_db(&db);
            let session = commands::restore_session(&db);
            let may_track = commands::can_track(&session);

            let provider: Arc<dyn platform::ActivityProvider> = Arc::from(platform::provider());
            let clock = Arc::new(SystemClock);
            let mut engine = Engine::new(clock, provider, db, settings, user_id, device_id);

            // Crash recovery (task 6): close anything left open by a previous run, and
            // auto-resume if tracking was on when the app last stopped running -- but only
            // for someone who is logged in and has given consent.
            let auto_resumed = engine.recover_on_startup(may_track);

            let engine = Arc::new(Mutex::new(engine));
            let sync = sync::worker::SyncHandle::default();
            let logged_in = session.is_some();
            app.manage(commands::AppState {
                engine: engine.clone(),
                api,
                sync: sync.clone(),
                session: Mutex::new(session),
                _log_guard: log_guard,
            });

            // Sync worker (docs §11.1): every 2 minutes while logged in, on demand, and
            // /health polling while offline. A first pass right after startup.
            sync::worker::spawn(app.handle().clone(), sync.clone());
            if logged_in {
                sync.trigger();
            }

            // Tick loop: every 2s, independent of window visibility (task 4).
            let tick_engine = engine.clone();
            tauri::async_runtime::spawn(async move {
                let mut interval = tokio::time::interval(std::time::Duration::from_secs(2));
                loop {
                    interval.tick().await;
                    let mut e = tick_engine.lock().unwrap_or_else(|e| e.into_inner());
                    e.tick();
                }
            });

            // Event listener (task 5): platform lock/unlock/sleep/wake/shutdown -> engine.
            let (tx, rx) = std::sync::mpsc::channel();
            platform::start_system_events(tx);
            let event_engine = engine.clone();
            std::thread::Builder::new()
                .name("engine-events".into())
                .spawn(move || {
                    for sys_event in rx {
                        let mut e = event_engine.lock().unwrap_or_else(|e| e.into_inner());
                        e.handle_system_event(sys_event);
                    }
                })?;

            // Once-a-day housekeeping (docs §7.4): delete SYNCED sessions and DONE queue
            // rows older than 7 days. Unsent (OPEN/PENDING/FAILED) data is never touched.
            // `interval()` fires immediately on the first tick too, so this also runs
            // once per launch, not just once per 24h of continuous uptime.
            let purge_engine = engine.clone();
            tauri::async_runtime::spawn(async move {
                let mut interval = tokio::time::interval(std::time::Duration::from_secs(24 * 60 * 60));
                loop {
                    interval.tick().await;
                    let e = purge_engine.lock().unwrap_or_else(|e| e.into_inner());
                    let cutoff = chrono::Utc::now().timestamp_millis() - 7 * 24 * 60 * 60 * 1000;
                    if let Err(err) = e.db().purge_old_synced(cutoff) {
                        tracing::error!(?err, "daily housekeeping: purge_old_synced failed");
                    }
                }
            });

            if auto_resumed {
                let _ = app.handle().emit("tracking-resumed", ());
            }

            setup_tray(app.handle())?;
            Ok(())
        })
        .on_window_event(|window, event| {
            // Closing the window hides it to the tray; tracking keeps running.
            if let WindowEvent::CloseRequested { api, .. } = event {
                let _ = window.hide();
                api.prevent_close();
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running the Time Tracker app");
}

fn setup_tray(app: &AppHandle) -> tauri::Result<()> {
    let show = MenuItem::with_id(app, "show", "Show", true, None::<&str>)?;
    let quit = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&show, &quit])?;

    let mut tray = TrayIconBuilder::with_id("main")
        .tooltip("Time Tracker")
        .menu(&menu)
        .show_menu_on_left_click(false)
        .on_menu_event(|app, event| match event.id().as_ref() {
            "show" => show_main_window(app),
            "quit" => app.exit(0),
            _ => {}
        })
        .on_tray_icon_event(|tray, event| {
            if let TrayIconEvent::Click {
                button: MouseButton::Left,
                button_state: MouseButtonState::Up,
                ..
            } = event
            {
                show_main_window(tray.app_handle());
            }
        });
    if let Some(icon) = app.default_window_icon() {
        tray = tray.icon(icon.clone());
    }
    tray.build(app)?;
    Ok(())
}

fn show_main_window(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.unminimize();
        let _ = window.show();
        let _ = window.set_focus();
    }
}
