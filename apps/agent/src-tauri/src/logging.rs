//! Rolling daily log file (task 10). Never logs window titles or idle-app titles --
//! only app/process names, durations, and state transitions.

use tauri::Manager;

pub fn init(app: &tauri::AppHandle) -> Result<tracing_appender::non_blocking::WorkerGuard, Box<dyn std::error::Error>> {
    let log_dir = app.path().app_log_dir()?;
    std::fs::create_dir_all(&log_dir)?;
    prune_old_logs(&log_dir, 7);

    let file_appender = tracing_appender::rolling::daily(&log_dir, "tracker.log");
    let (non_blocking, guard) = tracing_appender::non_blocking(file_appender);
    tracing_subscriber::fmt()
        .with_writer(non_blocking)
        .with_ansi(false)
        .with_env_filter(tracing_subscriber::EnvFilter::new("info"))
        .init();

    Ok(guard)
}

fn prune_old_logs(log_dir: &std::path::Path, keep: usize) {
    let Ok(entries) = std::fs::read_dir(log_dir) else {
        return;
    };
    let mut files: Vec<_> = entries
        .filter_map(|e| e.ok())
        .filter(|e| {
            e.file_name()
                .to_string_lossy()
                .starts_with("tracker.log.")
        })
        .collect();
    files.sort_by_key(|e| e.file_name());
    if files.len() > keep {
        for old in &files[..files.len() - keep] {
            let _ = std::fs::remove_file(old.path());
        }
    }
}
