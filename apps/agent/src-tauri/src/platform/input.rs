//! Input statistics for the activity check (docs/DEVELOPMENT_PLAN.md §16). Only counts and the shape of mouse movement
//! are kept: never which key was pressed, never text. Input is told apart by where it came from: real hardware has a
//! device, input sent by software (a script, a macro program, an auto-clicker) does not.
//!
//! The Windows side feeds a collector from raw input messages; everything here is plain code that can be tested anywhere.

use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::Mutex;

use serde::{Deserialize, Serialize};

/// Mouse events closer together than this belong to one burst of movement (one hand movement, or one jiggle).
const BURST_GAP_MS: u64 = 500;
/// A burst that travels this many pixels or less is a "tiny" one.
const TINY_BURST_PX: f64 = 10.0;
/// Gaps between bursts longer than this are not part of a rhythm any more (the person left).
const MAX_RHYTHM_GAP_MS: f64 = 600_000.0;
/// At least this many bursts are needed before their regularity means anything.
const MIN_BURSTS_FOR_RHYTHM: u64 = 5;

/// The counts of one stretch of tracked time (a chunk). Serialised as the API expects (camelCase).
#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct InputStats {
    pub hw_keys: u64,
    pub sw_keys: u64,
    pub hw_mouse: u64,
    pub sw_mouse: u64,
    pub hw_clicks: u64,
    pub sw_clicks: u64,
    pub mouse_distance_px: u64,
    /// Percent of movement bursts that travelled 10 px or less. Left out when there were too few bursts to say.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub tiny_move_share: Option<u64>,
    /// How irregular the time between movement bursts is, times 100 (0 = perfectly regular, like a timer).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub move_interval_cv: Option<u64>,
    /// Seconds in which the system saw input but no hardware event arrived.
    pub sw_only_seconds: u64,
}

impl InputStats {
    pub fn is_empty(&self) -> bool {
        *self == InputStats::default()
    }
}

#[derive(Default)]
struct Accum {
    stats: InputStats,
    distance: f64,
    // the burst being followed
    last_move_ms: Option<u64>,
    burst_start_ms: Option<u64>,
    burst_path: f64,
    bursts: u64,
    tiny_bursts: u64,
    // running mean and spread of the gaps between burst starts (Welford)
    gaps: u64,
    gap_mean: f64,
    gap_m2: f64,
    last_absolute: Option<(i32, i32)>,
}

impl Accum {
    fn end_burst(&mut self) {
        if self.burst_start_ms.is_some() {
            self.bursts += 1;
            if self.burst_path <= TINY_BURST_PX {
                self.tiny_bursts += 1;
            }
        }
        self.burst_path = 0.0;
    }
}

pub struct InputCollector {
    inner: Mutex<Accum>,
    enabled: AtomicBool,
    /// Every hardware event ever seen; the caller compares it between two moments.
    hardware_total: AtomicU64,
}

impl Default for InputCollector {
    fn default() -> Self {
        Self::new()
    }
}

impl InputCollector {
    pub fn new() -> Self {
        Self {
            inner: Mutex::new(Accum::default()),
            enabled: AtomicBool::new(true),
            hardware_total: AtomicU64::new(0),
        }
    }

    /// While off nothing is counted (a superadmin switched the detection off for this person).
    pub fn set_enabled(&self, on: bool) {
        self.enabled.store(on, Ordering::Relaxed);
        if !on {
            *self.lock() = Accum::default();
        }
    }

    pub fn hardware_total(&self) -> u64 {
        self.hardware_total.load(Ordering::Relaxed)
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, Accum> {
        self.inner.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn note_hardware(&self, hardware: bool) {
        if hardware {
            self.hardware_total.fetch_add(1, Ordering::Relaxed);
        }
    }

    /// A key went down. The key itself is never looked at.
    pub fn record_key(&self, hardware: bool) {
        self.note_hardware(hardware);
        if !self.enabled.load(Ordering::Relaxed) {
            return;
        }
        let mut a = self.lock();
        if hardware {
            a.stats.hw_keys += 1;
        } else {
            a.stats.sw_keys += 1;
        }
    }

    pub fn record_click(&self, hardware: bool) {
        self.note_hardware(hardware);
        if !self.enabled.load(Ordering::Relaxed) {
            return;
        }
        let mut a = self.lock();
        if hardware {
            a.stats.hw_clicks += 1;
        } else {
            a.stats.sw_clicks += 1;
        }
    }

    /// A mouse move of (`dx`, `dy`) pixels at `at_ms` (any steady millisecond clock).
    pub fn record_move(&self, hardware: bool, dx: i32, dy: i32, at_ms: u64) {
        self.note_hardware(hardware);
        if !self.enabled.load(Ordering::Relaxed) {
            return;
        }
        let mut a = self.lock();
        if hardware {
            a.stats.hw_mouse += 1;
        } else {
            a.stats.sw_mouse += 1;
        }
        let step = f64::from(dx).hypot(f64::from(dy));
        a.distance += step;

        let new_burst = match a.last_move_ms {
            Some(last) => at_ms.saturating_sub(last) >= BURST_GAP_MS,
            None => true,
        };
        if new_burst {
            a.end_burst();
            if let Some(previous_start) = a.burst_start_ms {
                let gap = at_ms.saturating_sub(previous_start) as f64;
                if gap <= MAX_RHYTHM_GAP_MS {
                    a.gaps += 1;
                    let delta = gap - a.gap_mean;
                    a.gap_mean += delta / a.gaps as f64;
                    a.gap_m2 += delta * (gap - a.gap_mean);
                }
            }
            a.burst_start_ms = Some(at_ms);
        }
        a.burst_path += step;
        a.last_move_ms = Some(at_ms);
    }

    /// The position of an absolute move (a script or tablet says "go to x, y"): the step is the change from the last one.
    pub fn record_absolute_move(&self, hardware: bool, x: i32, y: i32, at_ms: u64) {
        let step = {
            let mut a = self.lock();
            let step = a.last_absolute.map(|(px, py)| (x - px, y - py)).unwrap_or((0, 0));
            a.last_absolute = Some((x, y));
            step
        };
        self.record_move(hardware, step.0, step.1, at_ms);
    }

    /// Seconds in which the system saw input but no hardware event arrived.
    pub fn note_software_only(&self, seconds: u64) {
        if self.enabled.load(Ordering::Relaxed) {
            self.lock().stats.sw_only_seconds += seconds;
        }
    }

    /// Everything counted since the last call, and a fresh start.
    pub fn take(&self) -> InputStats {
        let mut a = self.lock();
        a.end_burst();
        let bursts = a.bursts;
        let mut stats = a.stats.clone();
        stats.mouse_distance_px = a.distance.round() as u64;
        if bursts >= MIN_BURSTS_FOR_RHYTHM {
            stats.tiny_move_share = Some((a.tiny_bursts * 100 / bursts).min(100));
            if a.gaps >= MIN_BURSTS_FOR_RHYTHM - 1 && a.gap_mean > 0.0 {
                let spread = (a.gap_m2 / a.gaps as f64).sqrt();
                stats.move_interval_cv = Some((spread / a.gap_mean * 100.0).round().min(100_000.0) as u64);
            }
        }
        let last_absolute = a.last_absolute;
        *a = Accum::default();
        a.last_absolute = last_absolute;
        stats
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hardware_and_software_events_are_counted_apart() {
        let c = InputCollector::new();
        c.record_key(true);
        c.record_key(true);
        c.record_key(false);
        c.record_click(false);
        c.record_move(true, 3, 4, 0);
        c.record_move(false, 1, 0, 10);

        let s = c.take();

        assert_eq!((s.hw_keys, s.sw_keys, s.hw_clicks, s.sw_clicks, s.hw_mouse, s.sw_mouse), (2, 1, 0, 1, 1, 1));
        assert_eq!(s.mouse_distance_px, 6); // 5 + 1
        assert_eq!(c.hardware_total(), 3);
    }

    #[test]
    fn taking_starts_a_fresh_count() {
        let c = InputCollector::new();
        c.record_key(true);
        assert_eq!(c.take().hw_keys, 1);
        assert!(c.take().is_empty());
    }

    #[test]
    fn nothing_is_counted_while_switched_off_and_what_was_counted_is_forgotten() {
        let c = InputCollector::new();
        c.record_key(true);
        c.set_enabled(false);
        c.record_key(true);
        c.note_software_only(5);
        assert!(c.take().is_empty());
        c.set_enabled(true);
        c.record_key(true);
        assert_eq!(c.take().hw_keys, 1);
    }

    /// `n` jiggles: out and back within 20 ms, every `period_ms`.
    fn jiggle(c: &InputCollector, n: u64, period_ms: u64, hardware: bool) {
        for i in 0..n {
            let t = i * period_ms;
            c.record_move(hardware, 1, 0, t);
            c.record_move(hardware, -1, 0, t + 20);
        }
    }

    #[test]
    fn a_jiggler_is_tiny_and_perfectly_regular() {
        let c = InputCollector::new();
        jiggle(&c, 20, 30_000, true);

        let s = c.take();

        assert_eq!(s.tiny_move_share, Some(100));
        assert_eq!(s.move_interval_cv, Some(0));
        assert_eq!(s.hw_mouse, 40);
    }

    #[test]
    fn a_person_moving_the_mouse_is_not_regular() {
        let c = InputCollector::new();
        // bursts of large movement at uneven times
        let starts = [0u64, 3_000, 4_100, 19_000, 21_500, 60_000, 61_000, 95_000];
        for &t in &starts {
            for step in 0..10 {
                c.record_move(true, 40, 25, t + step * 8);
            }
        }

        let s = c.take();

        assert_eq!(s.tiny_move_share, Some(0));
        assert!(s.move_interval_cv.unwrap() > 50, "cv was {:?}", s.move_interval_cv);
    }

    #[test]
    fn too_few_bursts_say_nothing_about_rhythm() {
        let c = InputCollector::new();
        jiggle(&c, 3, 30_000, true);

        let s = c.take();

        assert_eq!(s.tiny_move_share, None);
        assert_eq!(s.move_interval_cv, None);
    }

    #[test]
    fn continuous_movement_is_one_burst() {
        let c = InputCollector::new();
        for i in 0..200 {
            c.record_move(true, 5, 5, i * 10);
        }

        let s = c.take();

        assert_eq!(s.tiny_move_share, None);
        assert_eq!(s.hw_mouse, 200);
    }

    #[test]
    fn absolute_moves_count_the_change_from_the_last_position() {
        let c = InputCollector::new();
        c.record_absolute_move(false, 100, 100, 0);
        c.record_absolute_move(false, 103, 104, 10);

        let s = c.take();

        assert_eq!(s.sw_mouse, 2);
        assert_eq!(s.mouse_distance_px, 5);
    }

    #[test]
    fn software_only_seconds_add_up() {
        let c = InputCollector::new();
        c.note_software_only(2);
        c.note_software_only(3);
        assert_eq!(c.take().sw_only_seconds, 5);
    }

    #[test]
    fn stats_go_on_the_wire_in_the_shape_the_server_expects() {
        let c = InputCollector::new();
        c.record_key(true);
        let json = serde_json::to_value(c.take()).unwrap();

        assert_eq!(json["hwKeys"], 1);
        assert_eq!(json["swOnlySeconds"], 0);
        assert!(json.get("moveIntervalCv").is_none());
    }
}
