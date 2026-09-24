//! Phase 0 background logger. Writes one line every 2 seconds plus every
//! system event, so we can prove tracking keeps running while the window is
//! hidden. Replaced by the real tracking engine in Phase 3.
//!
//! Window titles are deliberately NOT written to the log.

use std::collections::VecDeque;
use std::fs::{File, OpenOptions};
use std::io::Write;
use std::sync::{mpsc, Arc, Mutex};
use std::time::{Duration, Instant};

use tauri::{AppHandle, Emitter, Manager};

use crate::commands::{AppState, SystemEventRecord, MAX_RECENT_EVENTS};
use crate::platform::{self, ActivityProvider};

const TICK: Duration = Duration::from_secs(2);

pub fn start(app: &AppHandle) -> Result<(), Box<dyn std::error::Error>> {
    let log_dir = app.path().app_log_dir()?;
    std::fs::create_dir_all(&log_dir)?;
    let log_path = log_dir.join("activity-spike.log");
    let log = Arc::new(Mutex::new(
        OpenOptions::new()
            .create(true)
            .append(true)
            .open(&log_path)?,
    ));

    let provider: Arc<dyn ActivityProvider> = Arc::from(platform::provider());
    let recent_events = Arc::new(Mutex::new(VecDeque::new()));

    app.manage(AppState {
        provider: provider.clone(),
        recent_events: recent_events.clone(),
        log_path,
    });

    write_line(&log, "--- app started ---");

    // Activity tick loop.
    let tick_log = log.clone();
    tauri::async_runtime::spawn(async move {
        let mut interval = tokio::time::interval(TICK);
        let mut last_tick: Option<Instant> = None;
        loop {
            interval.tick().await;
            let now = Instant::now();
            let gap_ms = last_tick.map_or(0, |t| now.duration_since(t).as_millis());
            last_tick = Some(now);

            let idle = provider.idle_seconds();
            let line = match provider.current_activity() {
                Some(a) => format!(
                    "TICK\tapp={}\tprocess={}\tidle={idle}s\tgap_ms={gap_ms}",
                    a.app_name, a.process_name
                ),
                None => format!("TICK\tapp=<none>\tidle={idle}s\tgap_ms={gap_ms}"),
            };
            write_line(&tick_log, &line);
        }
    });

    // System events: log them, keep the last few for the UI, and notify the UI.
    let (tx, rx) = mpsc::channel();
    platform::start_system_events(tx);
    let handle = app.clone();
    std::thread::Builder::new()
        .name("system-event-logger".into())
        .spawn(move || {
            for kind in rx {
                let record = SystemEventRecord {
                    kind,
                    at: chrono::Local::now().to_rfc3339(),
                };
                write_line(&log, &format!("EVENT\t{kind:?}"));
                {
                    let mut events = recent_events.lock().unwrap_or_else(|e| e.into_inner());
                    events.push_front(record.clone());
                    events.truncate(MAX_RECENT_EVENTS);
                }
                let _ = handle.emit("system-event", record);
            }
        })?;

    Ok(())
}

fn write_line(log: &Mutex<File>, text: &str) {
    let timestamp = chrono::Local::now().format("%Y-%m-%d %H:%M:%S%.3f");
    let mut file = log.lock().unwrap_or_else(|e| e.into_inner());
    let _ = writeln!(file, "{timestamp}\t{text}");
    let _ = file.flush();
}
