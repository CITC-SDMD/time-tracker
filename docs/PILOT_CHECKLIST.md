# Pilot checklist (Phase 7)

Phase 7 of `docs/DEVELOPMENT_PLAN.md` is a one-week pilot on 2 or 3 real PCs against a real server, using the release build. The parts that could be done without a server or people are already done and written in `docs/SECURITY_REVIEW.md`. This page is what is left: each step says who does it, the exact command, what a pass looks like, and where to write the result. Nothing here needs the developer's code to change unless a step fails.

Tick the boxes and fill the tables as you go. The report template is at the end (section 8).

## 1. Before the pilot: the release

- [ ] Every check in `docs/RELEASE.md` section 1 is green on the commit you are piloting.
- [ ] The installer is built with `TRACKER_API_URL` pointing at the pilot server (`docs/RELEASE.md` section 4) and tested on one clean PC first.
- [ ] Two or three pilot people are chosen, told what is tracked (the notice they accept in the app), and know who to call. At least one uses the PC all day; one uses a laptop that is closed and carried.
- [ ] One person's manager is in the pilot too, so the dashboard is looked at from both sides.

## 2. The server (someone with access to it)

Run these on the pilot server, then the two from another machine. Write the output in the table.

| Check | Command | Pass | Result |
|---|---|---|---|
| Settings are safe | `php artisan tracker:security-check` (in `apps/api`) | "Nothing that must be fixed" or "Everything checked is fine" | |
| Only 22, 80, 443 open | `nmap -Pn <server address>` from **another** machine | exactly those three | |
| http goes to https | `curl -I http://<server address>` | a `301` to the https address | |
| The security headers are sent | `curl -I https://<server address>/` | `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options` | |
| MySQL is not reachable | `mysql -h <server address> -u tracker -p` from another machine | connection refused or timed out | |
| `.env` is private | `ls -l apps/api/.env` | not readable by "others" | |
| The queue worker runs | `sudo systemctl status tracker-queue` and `php artisan queue:failed` | active; empty | |
| A screenshot of 1.5 MB is accepted | take one screenshot in the app (Settings must have screenshots on) | it appears on the dashboard | |
| Updates are on | `sudo apt list --upgradable` | nothing security-related waiting | |

## 3. The security sweep on the real server (test 7.3)

The tests behind these are automatic in the suite (`SecurityTest`, `TenantIsolationTest`, `PlatformTest`), so this is a quick repeat by hand on the real address, as two different people in two different organizations if the pilot has them (otherwise two people of one office).

- [ ] Sign in wrongly 9 times in a minute (dashboard): the 9th is refused (`429`).
- [ ] Open a person's page of another organization by its id in the address bar: "not found".
- [ ] As a person without the "view timeline" permission, open a colleague's timeline by its address: refused.
- [ ] Deactivate a pilot person: their desktop app signs out at the next sync and their dashboard page stops working at once.
- [ ] A screenshot's address opened in a private window, signed out, is refused (`401`).
- [ ] Turn on two-factor sign-in for one pilot admin (profile page), sign out and in again: the code is asked for. Then reset it from another admin's view of that person and sign in with the password alone.
- [ ] On a pilot PC, open the profile page and check "Desktop app sign-ins" lists that PC; sign it out and check the desktop app asks to log in again.
- [ ] **On the first PC that had the previous desktop version installed**, install the new one over it: it starts, shows the same history, and `tracker.db` in `%APPDATA%com.office.timetracker` holds no readable window titles (open it in a text editor or search it for a title you know). Every screen of the app renders (sign in, notice, main, settings, screenshots).

## 4. Backup and restore (test 7.6)

On the server, with a scratch database that already exists and holds nothing you want:

```bash
DB_NAME=tracker_prod DB_USER=tracker DRILL_DB=tracker_restore_drill ./scripts/backup-restore-drill.sh
```

- [ ] Every table shows `ok` and the last line says the backup restores completely.
- [ ] The daily backup job exists and copies the file **off** the server; the newest copy has been looked at.
- [ ] How to restore for real is written down where the person on call can find it (`docs/RELEASE.md` section 7 has the steps).

Result and date: ______________________

## 5. The week: what each pilot person does

### Day 1: a normal day (test 7.1)
The person writes down, by hand, when they started, when they had lunch and when they finished. In the evening compare with the dashboard's timeline.

- [ ] Start and end are within 1 minute of the notes.
- [ ] Lunch shows as a gap or as idle.
- [ ] The total for the day is within 5 minutes of what the person thinks they worked.

| Person | Notes: start / lunch / end | Dashboard: start / lunch / end | Pass |
|---|---|---|---|
| | | | |

### Day 2: everything breaks (test 7.2, edge cases §13)
During **one** tracked hour, in this order: turn Wi-Fi off and on three times; kill the app once (Task Manager, then start it again); put the PC to sleep once; lock it once.

- [ ] The app says "waiting to send" while offline and "All data sent" after.
- [ ] Sessions are missing only for the times the app was dead, the PC asleep or locked.
- [ ] No duplicate blocks on the timeline.
- [ ] After a restart the app resumes tracking with a notice (edge case 8, 9).

Then walk through the rest of §13 that has not been seen yet (rapid app switching, a meeting with no input, a session over midnight, changing the system clock, a second PC for the same person, a deactivated person, an old app version) and tick each as it is seen:

| §13 # | Seen and as expected? | Notes |
|---|---|---|
| 1, 2, 3 | | |
| 8, 9, 10, 11, 12 | | |
| 13, 14, 15 | | |
| 18 | | |
| 20, 21, 22 | | |
| 23 | | |
| 33, 34 | | |
| 36 | | |

### Day 3: the activity check (see `docs/LIVE_TEST_ACTIVITY_CHECK.md`)
Do tests 1, 2, 7 and 8 of that page on one pilot PC and fill its table. Test 1 (a normal day must show **no flag**) is the most important one: a false flag on an honest person is worse than a missed one.

### Days 1 to 7: every person, once a day
- [ ] Look at the app's status line before leaving: "All data sent".
- [ ] Note anything odd (a wrong app name, a freeze, a pop-up, a slow PC) in the report.

## 6. Duplicates and missing sessions (test 7.4)

At the end of the week, for **each** pilot PC. On the PC, close the app first, then export the ids it marked as sent (needs the `sqlite3` command: `winget install SQLite.SQLite`):

```powershell
sqlite3 "$env:APPDATA\com.office.timetracker\tracker.db" "SELECT id FROM sessions WHERE sync_status='SYNCED';" > synced-ids.txt
```

Copy the file to the server and run, with the person's id from the dashboard address:

```bash
php artisan tracker:compare-sessions synced-ids.txt <person id>
```

- [ ] "0 missing, 0 extra" for every PC.

(The desktop app deletes sent rows after 7 days, so run this within the week. If the path above does not exist, look for `tracker.db` under `%APPDATA%` and `%LOCALAPPDATA%`.)

| PC / person | Ids in file | Result |
|---|---|---|
| | | |

## 7. Resources (test 7.5, §14) and the server's load

On each pilot PC after the week (Task Manager, Details tab, "Time Tracker" and its "msedgewebview2" child processes):

| PC | CPU, window hidden (target under 1%) | CPU, window open (under 3%) | Memory total (under 150 MB) | `tracker.db` size (under 20 MB) | Network per day (under 2 MB) |
|---|---|---|---|---|---|
| | | | | | |

On the server, once a day at a busy time: `uptime`, `free -m`, `df -h` and `sudo mysqladmin status`; and at the end of the week, the size of the database and of the screenshot storage:

| Day | Load | Free memory | Disk used | Queue failures | `laravel.log` errors |
|---|---|---|---|---|---|
| 1 | | | | | |
| 7 | | | | | |

Database size: ________ MB. Screenshot storage: ________ MB. Slowest dashboard page (seconds to load): ________ (target under 2).

## 8. Report (fill this in and keep it with the release notes)

**Pilot period:** ____ to ____  **Commit:** ________  **Desktop app version:** ________  **Server:** ________

**People and PCs:** 

**Passed:** (list the test numbers 7.1 to 7.6 and the checks in section 2 that passed)

**Failed or not done, and why:** 

**Bugs found** (what happened, on which PC, how it was fixed or when it will be):

| # | What happened | Severity (blocks a rollout / annoying / cosmetic) | Fixed in commit / planned |
|---|---|---|---|
| | | | |

**False flags from the activity check** (any person flagged who was working honestly, with the reasons shown):

**Things people said** (slow PC, confusing text, privacy questions):

**Numbers:** the tables in sections 6 and 7 attached.

**Still open before more offices join** (from `docs/SECURITY_REVIEW.md` section 6 and `docs/RELEASE.md` section 9): code signing, the upgrade over an old installer, the legal review, outside penetration test, monitoring.

**Decision:** ☐ ready for the first office  ☐ fix the items above and repeat the failed tests  ☐ not ready

Signed off by: ______________  Date: ________
