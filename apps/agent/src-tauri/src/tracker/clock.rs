//! `now_wall()` is what gets persisted (UTC, for `started_at`/`ended_at`); `now_mono()`
//! is what durations and gap/clock-jump detection are computed from, since it can't be
//! changed by the user or NTP and survives a wall-clock jump intact.

use std::sync::Mutex;
use std::time::{Duration, Instant};

use chrono::{DateTime, Utc};

pub trait Clock: Send + Sync {
    fn now_wall(&self) -> DateTime<Utc>;
    fn now_mono(&self) -> Instant;
}

pub struct SystemClock;

impl Clock for SystemClock {
    fn now_wall(&self) -> DateTime<Utc> {
        Utc::now()
    }

    fn now_mono(&self) -> Instant {
        Instant::now()
    }
}

/// Test double. `advance()` moves both clocks together (a normal tick); `jump_wall_only()`
/// moves only the wall clock, simulating the user (or NTP) changing the system time
/// without any real time passing — `Instant` has no public constructor for an arbitrary
/// point, so `now_mono()` is a fixed base plus a manually tracked offset instead.
pub struct FakeClock {
    base_mono: Instant,
    mono_offset: Mutex<Duration>,
    wall: Mutex<DateTime<Utc>>,
}

impl FakeClock {
    pub fn new(start_wall: DateTime<Utc>) -> Self {
        Self {
            base_mono: Instant::now(),
            mono_offset: Mutex::new(Duration::ZERO),
            wall: Mutex::new(start_wall),
        }
    }

    pub fn advance(&self, d: Duration) {
        *self.mono_offset.lock().unwrap() += d;
        *self.wall.lock().unwrap() += chrono::Duration::from_std(d).unwrap();
    }

    pub fn jump_wall_only(&self, d: chrono::Duration) {
        *self.wall.lock().unwrap() += d;
    }
}

impl Clock for FakeClock {
    fn now_wall(&self) -> DateTime<Utc> {
        *self.wall.lock().unwrap()
    }

    fn now_mono(&self) -> Instant {
        self.base_mono + *self.mono_offset.lock().unwrap()
    }
}
