//! Known macro programs (docs/DEVELOPMENT_PLAN.md §16). The names of the running processes are compared with a list of
//! programs made to move the mouse, click or type for a person, right here on the computer. Only names from that list
//! are ever reported; every other process name stays on the PC.

use std::sync::Mutex;
use std::time::{Duration, Instant};

use super::imp;

/// Used until the server has sent its own list (config/integrity.php on the server).
pub const DEFAULT_TOOLS: &[&str] = &[
    "autohotkey", "autohotkey32", "autohotkey64", "autohotkeyu32", "autohotkeyu64", "autohotkeyux",
    "tinytask", "pulover", "macrorecorder", "macroexpress", "jitterclick", "mousejiggler", "mouse_jiggler",
    "movemouse", "caffeine", "autoclicker", "opautoclicker", "gsautoclicker", "murgeeclicker", "ghostmouse",
    "wiggle", "jiggler", "keepnite", "nomousejiggler",
];

/// How long a scan stays valid.
const REFRESH: Duration = Duration::from_secs(5 * 60);

/// The entries of `known` that some running process is called (as a whole name or as a beginning), in the list's own
/// spelling. Process names are compared in lowercase without ".exe".
pub fn matching(known: &[String], running: &[String]) -> Vec<String> {
    let mut found: Vec<String> = Vec::new();
    for process in running {
        let name = process.trim().to_lowercase();
        let name = name.strip_suffix(".exe").unwrap_or(&name);
        for tool in known {
            if (name == tool || name.starts_with(tool.as_str())) && !found.contains(tool) {
                found.push(tool.clone());
            }
        }
    }
    found.sort();
    found.truncate(10);
    found
}

static CACHE: Mutex<Option<(Instant, Vec<String>)>> = Mutex::new(None);

/// The known macro programs running now, looked up at most every five minutes.
pub fn current(known: &[String]) -> Vec<String> {
    let mut cache = CACHE.lock().unwrap_or_else(|e| e.into_inner());
    if let Some((at, found)) = cache.as_ref() {
        if at.elapsed() < REFRESH {
            return found.clone();
        }
    }
    let found = matching(known, &imp::running_process_names());
    *cache = Some((Instant::now(), found.clone()));
    found
}

pub fn default_list() -> Vec<String> {
    DEFAULT_TOOLS.iter().map(|s| (*s).to_owned()).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn list(items: &[&str]) -> Vec<String> {
        items.iter().map(|s| (*s).to_owned()).collect()
    }

    #[test]
    fn only_names_of_the_known_list_are_reported() {
        let known = list(&["autohotkey", "tinytask"]);
        let running = list(&["chrome.exe", "Code.exe", "AutoHotkey64.exe", "my-secret-editor.exe", "svchost.exe"]);

        assert_eq!(matching(&known, &running), vec!["autohotkey".to_owned()]);
    }

    #[test]
    fn nothing_running_that_is_known_reports_nothing() {
        assert!(matching(&list(&["tinytask"]), &list(&["chrome.exe", "explorer.exe"])).is_empty());
        assert!(matching(&list(&["tinytask"]), &[]).is_empty());
    }

    #[test]
    fn a_program_running_twice_is_reported_once() {
        let found = matching(&list(&["tinytask"]), &list(&["TinyTask.exe", "tinytask.exe"]));
        assert_eq!(found, vec!["tinytask".to_owned()]);
    }

    #[test]
    fn scanning_this_computer_works() {
        // whatever runs here, the answer only ever holds names from the list
        let known = default_list();
        for name in current(&known) {
            assert!(known.contains(&name));
        }
    }
}
