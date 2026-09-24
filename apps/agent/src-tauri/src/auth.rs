//! The Sanctum token lives only in Windows Credential Manager (docs §9.2, Test 2.11) —
//! never in SQLite, never handed to the Vue side.

const SERVICE: &str = "com.office.timetracker";
const ACCOUNT: &str = "sanctum-token";

fn entry() -> Result<keyring::Entry, String> {
    keyring::Entry::new(SERVICE, ACCOUNT).map_err(|e| e.to_string())
}

pub fn save_token(token: &str) -> Result<(), String> {
    entry()?.set_password(token).map_err(|e| e.to_string())
}

pub fn load_token() -> Option<String> {
    entry().ok()?.get_password().ok()
}

pub fn clear_token() {
    if let Ok(entry) = entry() {
        let _ = entry.delete_credential();
    }
}
