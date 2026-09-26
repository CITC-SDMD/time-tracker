//! When a screenshot is due (docs Phase 10). A pure function of the time, the last shot and the
//! office setting, so every rule is unit-tested without a screen, a clock or a database.
//!
//! - **Fixed** mode: one shot every N minutes, counted from the last shot. Tracking that starts after
//!   a long break takes its first shot straight away.
//! - **Random** mode: one shot per wall-clock N-minute block, at a random moment inside the block.
//!   Never more than one per block.
//!
//! Whether anyone is tracking is not decided here: the caller only asks while the state is `Tracking`
//! (active or idle time), so a paused, stopped or locked PC is never photographed.

/// The largest number of minutes accepted from the server; anything else means "off" (see `interval_ms`).
const ALLOWED_INTERVALS: [u32; 4] = [5, 10, 15, 30];

/// The rhythm in milliseconds, or `None` when screenshots are off (0) or the value is not one the
/// server may send (a wrong value must never make the app photograph every second).
pub fn interval_ms(minutes: u32) -> Option<i64> {
    ALLOWED_INTERVALS.contains(&minutes).then(|| i64::from(minutes) * 60_000)
}

/// Is a screenshot due at `now_ms`? `last_taken_ms` is when the previous one was taken (`None` if never).
/// `seed` makes the random moments differ between people and days (any stable number per person).
pub fn is_due(now_ms: i64, last_taken_ms: Option<i64>, interval_minutes: u32, random: bool, seed: u64) -> bool {
    let Some(interval) = interval_ms(interval_minutes) else {
        return false;
    };
    // a last shot dated in the future means the clock was set back: taking one now sets it right again,
    // where waiting for the clock to catch up would freeze the schedule for hours
    let last = last_taken_ms.filter(|&last| last <= now_ms);
    if last_taken_ms.is_some() && last.is_none() {
        return true;
    }

    if !random {
        return match last {
            None => true,
            Some(last) => now_ms - last >= interval,
        };
    }

    let block = now_ms.div_euclid(interval);
    if let Some(last) = last {
        if last.div_euclid(interval) >= block {
            return false; // this block already has its shot
        }
    }
    now_ms.rem_euclid(interval) >= random_offset_ms(block, seed, interval)
}

/// Where inside `block` the shot is taken: somewhere in the first part of the block, always leaving
/// at least 30 seconds so a late tick still lands inside it.
fn random_offset_ms(block: i64, seed: u64, interval: i64) -> i64 {
    let room = (interval - 30_000).max(1);
    (splitmix64(seed ^ (block as u64).wrapping_mul(0x9E37_79B9_7F4A_7C15)) % room as u64) as i64
}

/// A small, well-spread hash: the same input always gives the same number.
fn splitmix64(mut x: u64) -> u64 {
    x = x.wrapping_add(0x9E37_79B9_7F4A_7C15);
    x = (x ^ (x >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
    x = (x ^ (x >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
    x ^ (x >> 31)
}

/// A stable number for one person, so their random moments differ from everyone else's.
pub fn seed_for(user_id: &str) -> u64 {
    user_id.bytes().fold(0xCBF2_9CE4_8422_2325, |hash, b| (hash ^ u64::from(b)).wrapping_mul(0x0100_0000_01B3))
}

#[cfg(test)]
mod tests {
    use super::*;

    const MIN: i64 = 60_000;
    const T0: i64 = 1_800_000_000_000;

    #[test]
    fn off_and_unknown_intervals_never_take_a_shot() {
        for minutes in [0, 1, 7, 20, 60, 1000] {
            assert!(!is_due(T0, None, minutes, false, 1), "{minutes} minutes");
            assert!(!is_due(T0, None, minutes, true, 1), "{minutes} minutes, random");
        }
    }

    #[test]
    fn the_first_shot_is_taken_at_once_in_fixed_mode() {
        assert!(is_due(T0, None, 10, false, 1));
    }

    #[test]
    fn fixed_mode_waits_the_full_interval_from_the_last_shot() {
        assert!(!is_due(T0 + 9 * MIN + 59_000, Some(T0), 10, false, 1));
        assert!(is_due(T0 + 10 * MIN, Some(T0), 10, false, 1));
        assert!(is_due(T0 + 45 * MIN, Some(T0), 10, false, 1), "after a long break it is due at once, once");
    }

    #[test]
    fn a_new_interval_is_picked_up_at_once() {
        let last = Some(T0);
        assert!(!is_due(T0 + 6 * MIN, last, 10, false, 1));
        assert!(is_due(T0 + 6 * MIN, last, 5, false, 1));
    }

    #[test]
    fn a_clock_set_back_does_not_freeze_the_schedule() {
        // the last shot is dated in the future: one is taken now, which puts the record right
        assert!(is_due(T0, Some(T0 + 3_600_000), 10, false, 1));
        assert!(!is_due(T0 + MIN, Some(T0), 10, false, 1));
    }

    /// Ticks every 15 seconds through `minutes` minutes and returns when the shots were taken.
    fn simulate(minutes: i64, interval: u32, random: bool, seed: u64, start: i64, mut last: Option<i64>) -> Vec<i64> {
        let mut shots = Vec::new();
        let mut now = start;
        while now < start + minutes * MIN {
            if is_due(now, last, interval, random, seed) {
                shots.push(now);
                last = Some(now);
            }
            now += 15_000;
        }
        shots
    }

    #[test]
    fn random_mode_takes_exactly_one_shot_in_every_block() {
        let start = 60 * MIN * 480; // a block boundary for every allowed interval
        for interval in [5u32, 10, 15, 30] {
            let block = i64::from(interval) * MIN;
            let shots = simulate(6 * 60, interval, true, 42, start, None);
            let mut per_block = std::collections::BTreeMap::new();
            for shot in &shots {
                *per_block.entry(shot.div_euclid(block)).or_insert(0) += 1;
            }
            assert_eq!(per_block.len() as i64, 6 * 60 / i64::from(interval), "a shot in every block ({interval} min)");
            assert!(per_block.values().all(|&n| n == 1), "never two in one block ({interval} min)");
        }
    }

    #[test]
    fn random_moments_are_not_all_at_the_same_point_of_the_block() {
        let start = 60 * MIN * 480;
        let shots = simulate(6 * 60, 10, true, 7, start, None);
        let offsets: std::collections::BTreeSet<i64> = shots.iter().map(|s| s.rem_euclid(10 * MIN)).collect();
        assert!(offsets.len() > 10, "spread over the block, got {} different offsets", offsets.len());
        assert!(shots.iter().all(|s| s.rem_euclid(10 * MIN) < 10 * MIN - 30_000 + 15_000), "always inside the block");
    }

    #[test]
    fn two_people_do_not_get_the_same_random_moments() {
        let start = 60 * MIN * 480;
        let a = simulate(120, 10, true, seed_for("1"), start, None);
        let b = simulate(120, 10, true, seed_for("2"), start, None);
        assert_ne!(a, b);
    }

    #[test]
    fn random_mode_survives_a_restart_without_a_second_shot_in_the_block() {
        let start = 60 * MIN * 480;
        let first = simulate(30, 10, true, 9, start, None);
        // the app restarts after the first shot and knows only the time of the last one
        let again = simulate(30, 10, true, 9, first[0] + 15_000, Some(first[0]));
        assert!(again.iter().all(|s| s.div_euclid(10 * MIN) != first[0].div_euclid(10 * MIN)));
    }

    #[test]
    fn random_mode_that_was_not_tracking_at_the_moment_still_shoots_once_in_the_same_block() {
        // asked only late in the block (tracking resumed): the moment has passed, so one shot now, never two
        let start = 60 * MIN * 480;
        let late = start + 9 * MIN;
        assert!(is_due(late, None, 10, true, 3));
        assert!(!is_due(late + 15_000, Some(late), 10, true, 3));
    }

    #[test]
    fn seed_is_stable_and_differs_between_people() {
        assert_eq!(seed_for("abc"), seed_for("abc"));
        assert_ne!(seed_for("1"), seed_for("2"));
    }
}
