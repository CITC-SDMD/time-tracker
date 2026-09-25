pub mod clock;
pub mod engine;
pub mod state;

#[cfg(test)]
pub mod testing;

/// Reads `app_state.office_settings_json` and falls back to `OfficeSettings::default()`
/// when absent -- e.g. before Phase 2's login/settings sync ever populates it. Field
/// names match packages/shared's `OfficeSettings` wire shape so a later phase can write
/// this blob straight from the synced JSON with no translation.
pub fn load_office_settings(db: &crate::db::Db) -> state::OfficeSettings {
    db.get_app_state("office_settings_json")
        .and_then(|json| serde_json::from_str::<StoredOfficeSettings>(&json).ok())
        .map(|s| state::OfficeSettings {
            idle_limit_seconds: s.idle_threshold_seconds,
            title_mode: if s.window_title_mode == "app_only" {
                state::TitleMode::AppOnly
            } else {
                state::TitleMode::Full
            },
        })
        .unwrap_or_default()
}

#[derive(serde::Deserialize)]
#[serde(rename_all = "camelCase")]
struct StoredOfficeSettings {
    idle_threshold_seconds: u64,
    window_title_mode: String,
}
