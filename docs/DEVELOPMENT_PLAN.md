# Office Time Tracker — Development Plan

> **How to use this document:** Build the project one phase at a time, in order. Each phase lists its tasks, what "done" looks like, and a manual test plan. Do not start a phase until the previous phase's tests pass.

---

## 0. Decisions Already Made

These are fixed. Do not change them without asking the project owner.

| Topic | Decision |
|---|---|
| Who uses it | **One office only.** Internal tool, not a public product. No sign-up page. |
| Accounts | A **manager creates accounts for their own direct reports** from the dashboard (§9.1) — not a single "Admin" role. |
| Roles | An 8-role hierarchy, not just Admin/Employee — see §9.1. **OIC** → **Project Manager** → **Team Leader** → (**Lead Developer**, **Developer**, **Client Support**, **QA**, **System Analyst**). Everyone tracks their own time the same way; what differs is dashboard access and whose data you can see. |
| Database | **MySQL/MariaDB only** (server, self-hosted on the office server). SQLite only on the employee's computer. |
| Hosting | **Self-hosted on the office server**, reachable from the internet under a domain name (WFH employees are not on the office LAN). HTTPS via a reverse proxy (nginx) with a Let's Encrypt certificate. |
| Platform | **Windows 10 / 11** first. |
| Pause | Employees **can pause** tracking. |
| Editing time | Employees **cannot edit or delete** their time. |
| Meetings / no input | Shown as **"Idle (in Zoom)"** — idle, labeled with the app that was on screen. |
| Logout | App **sends all unsent data first**. If offline: show *"You're offline, your data will be sent next time you log in"* and keep the data safely on the computer. |
| Detailed session data | Kept **30 days**. |
| Daily summaries | Kept **3 months**. |
| Tracking on many PCs | **One computer at a time.** Starting on a second PC stops the first. |
| Screenshots | **Not part of this project.** No screenshot feature, no screenshot storage. |
| Productivity scores | **Never.** We show facts only. |
| Keystrokes / webcam / hidden tracking | **Never.** |

---

## 1. Executive Summary

We are building a time tracker for our office's work-from-home staff.

- Employees install a small **desktop app** (Windows). They log in and press **Start**.
- The app notices **which app is in front** (e.g. VS Code, Chrome) and **whether the person is idle**.
- It saves this as **sessions** ("VS Code, 09:00–09:22") on the computer first, so nothing is lost when the internet drops.
- Every 2 minutes it **sends new sessions** to our server (a **Laravel API**, self-hosted on the office server), which checks who is sending and saves them to **MySQL**.
- Managers (OIC, Project Managers, Team Leaders) open a **web dashboard** to see who is working now, daily totals, which apps were used, and a timeline — scoped to their own part of the org chart (§9.1).

The system reports **facts** (time, apps, idle). It never calculates a "productivity %".

---

## 2. Architecture

```text
EMPLOYEE COMPUTER (Windows, anywhere with internet)
┌───────────────────────────────────────────────┐
│  Desktop app (Tauri)                          │
│  ┌──────────────┐     ┌─────────────────────┐ │
│  │  Vue screens │◄───►│ Rust engine         │ │
│  │  (Nuxt)      │     │ - watches active app│ │
│  └──────────────┘     │ - watches idle      │ │
│                       │ - makes sessions    │ │
│                       │ - SQLite (local)    │ │
│                       │ - sends to server   │ │
│                       └──────────┬──────────┘ │
└──────────────────────────────────┼────────────┘
                                   │ HTTPS, every 2 min
                                   ▼
                    ┌────────────────────────────────┐
                    │ Office server (self-hosted)     │
                    │ nginx (TLS, Let's Encrypt)      │
MANAGER BROWSER      │   Laravel API                   │
┌──────────────┐    │   - checks login token          │
│ Nuxt         │───►│   - checks role + hierarchy     │───► MySQL
│ dashboard    │    │   - checks data                 │
│ (static,     │    │   - saves / reads data          │
│  served by   │    └────────────────────────────────┘
│  same nginx) │
└──────────────┘
        Laravel Sanctum = who you are (login)
```

**Key rules**

1. **Only Laravel talks to MySQL.** The desktop app and dashboard never touch the database directly; MySQL is bound to localhost/the private network, not exposed to the internet.
2. **The Rust engine does the tracking and the syncing.** The Vue screens only display and send button clicks. This means tracking and syncing keep running when the window is hidden.
3. **Local first.** Everything is saved in SQLite before it is sent.
4. **The server never trusts the app** about who the user is or what role they have. It reads that from the Sanctum token and the `users` row.
5. **Dashboard and API share one domain.** The Nuxt dashboard is built as static files and served by the same nginx as the API (API under `/api/v1/...`). Same-origin means no CORS to configure and Sanctum's cookie-based SPA auth works without extra setup.

---

## 3. Technology Stack

| Part | Tools | Notes |
|---|---|---|
| Desktop app | Tauri 2, Rust, Nuxt 4 (SPA mode, `ssr: false`), Vue 3, TypeScript, Tailwind | |
| Local storage | SQLite via `rusqlite` (bundled) | |
| Rust crates | `windows` (Win32 APIs), `rusqlite`, `tokio`, `reqwest` (rustls), `serde`, `uuid` (v7), `keyring`, `tracing` + `tracing-appender`, `chrono` + `chrono-tz` | |
| Tauri plugins | `single-instance`, `autostart`, `updater`, `notification`, tray icon (built in) | |
| Server | **Laravel 11** (PHP 8.3+), self-hosted on the office server | A conventional Laravel app, not serverless. `php artisan serve` for local dev; PHP-FPM + nginx in production. |
| Login | **Laravel Sanctum** — personal access tokens for the desktop agent, cookie-based SPA session for the dashboard | Both issued by the same Laravel app; no third-party auth provider. |
| Database | **MySQL 8 / MariaDB**, on the same office server (or a private-network DB host) | Laravel's query builder / Eloquent ORM. Not exposed to the internet — only Laravel connects to it. |
| Dashboard | Nuxt 4 (SPA mode, `nuxt generate`), Vue 3, TypeScript, Tailwind | Built as static files, served by the **same nginx** as the API (same origin as the Laravel app — no separate hosting, no CORS). |
| Tooling | pnpm workspaces (agent + dashboard), Composer (API), Tauri CLI, GitHub | |
| App updates | Installer + `latest.json` served as static files from the office server (nginx), uploaded by the release workflow over SSH | Keep only the last 3 versions on disk. |
| Mail | Laravel Mail via an SMTP relay (**TODO:** no relay chosen yet — see `docs/SETUP.md` for the placeholder `.env` settings to fill in) | Used for password-reset links and employee invites. |

**Important:** The desktop app must be built and tested on a **real Windows 10/11 machine** (or Windows VM). The Laravel API and dashboard can be developed on any OS (Laravel via Docker/Sail or a local PHP install; MySQL via Docker or a local install).

---

## 4. Repository Structure

```text
time-tracker/
├── apps/
│   ├── agent/                  # Desktop app (Tauri + Nuxt)
│   │   ├── app/                # Nuxt/Vue screens
│   │   │   ├── pages/          # login, consent, index (home), settings
│   │   │   ├── components/
│   │   │   └── composables/    # useTracking(), useSync() – wrap invoke() calls
│   │   ├── nuxt.config.ts
│   │   └── src-tauri/
│   │       ├── src/
│   │       │   ├── main.rs
│   │       │   ├── commands.rs         # all #[tauri::command] functions
│   │       │   ├── tracker/
│   │       │   │   ├── engine.rs       # the tracking state machine
│   │       │   │   └── clock.rs        # wall clock + monotonic clock, fakeable in tests
│   │       │   ├── platform/
│   │       │   │   ├── mod.rs          # ActivityProvider trait
│   │       │   │   ├── win.rs          # Win32 implementation
│   │       │   │   └── fallback.rs     # placeholder for other OSes
│   │       │   ├── db/
│   │       │   │   ├── mod.rs          # open, migrations, integrity check
│   │       │   │   └── migrations/     # 001_init.sql, ...
│   │       │   ├── sync/
│   │       │   │   ├── client.rs       # HTTP calls to the Laravel API
│   │       │   │   └── worker.rs       # background sync loop
│   │       │   ├── auth.rs             # login, token refresh, Credential Manager
│   │       │   └── logging.rs
│   │       ├── Cargo.toml
│   │       └── tauri.conf.json
│   ├── dashboard/              # Manager dashboard (Nuxt) — OIC / Project Manager / Team Leader
│   │   ├── app/pages/          # login, index, employees/[id], employees/manage, settings, audit
│   │   └── nuxt.config.ts
│   └── api/                    # Laravel API (PHP, its own Composer project — not a pnpm package)
│       ├── app/
│       │   ├── Http/
│       │   │   ├── Controllers/Api/   # MeController, AgentController, EmployeeController, AdminController
│       │   │   ├── Middleware/        # EnsureActiveUser, EnsureManager, EnsureSelfOrVisible, CheckAgentVersion
│       │   │   └── Requests/          # form request validation (AgentSyncRequest, etc.)
│       │   ├── Models/                # User, EmployeeStatus, Session, DailySummary, Device, OfficeSetting, AuditLog
│       │   ├── Services/              # SessionSyncService, SummaryService, TimelineService, HierarchyService
│       │   └── Console/Commands/      # PruneOldData (retention cleanup, scheduled)
│       ├── database/
│       │   ├── migrations/
│       │   └── seeders/               # OfficeSettingsSeeder, first-OIC console command
│       ├── routes/api.php
│       ├── tests/                     # Pest/PHPUnit
│       ├── .env.example
│       └── composer.json
├── packages/
│   └── shared/                 # TS types used by the dashboard and agent UI (API request/response shapes)
│       └── src/ (api.ts, session.ts, roles.ts)
├── docs/
│   ├── DEVELOPMENT_PLAN.md     # this file
│   ├── SETUP.md
│   └── RELEASE.md
├── .github/workflows/          # release.yml only (Phase 8: build, sign, upload on tag) — no CI/lint workflow
├── pnpm-workspace.yaml
└── package.json
```

**Note:** `apps/api` is a normal Laravel app with its own `composer.json` — it is not part of the pnpm workspace. Request/response **validation** lives in Laravel Form Requests (PHP), not zod; `packages/shared` now only holds the TypeScript **types** that mirror those shapes, kept in sync by hand (or generated later if that becomes worth automating).

---

## 5. Key Definitions (how every number is calculated)

| Term | Meaning | How it is calculated |
|---|---|---|
| **Application session** | A period when one app was in front and the person was active. | `endedAt − startedAt`, from timestamps. Never from counting polls. |
| **Idle session** | A period with no mouse/keyboard input for longer than the idle limit (default **5 min**). | Starts at the **last input time** (not when idle was noticed). Ends at the next input. Stores the app that was in front (`idleAppName`), shown as "Idle (in Zoom)". |
| **Active time** | Sum of all application sessions. | `Σ APPLICATION durations` |
| **Idle time** | Sum of all idle sessions. | `Σ IDLE durations` |
| **Tracked time** | Active + idle. | `Active + Idle` |
| **Not counted** | Paused, screen locked, computer asleep, app closed, not tracking. | These appear as **gaps** in the timeline. They are not in tracked time. |
| **App breakdown** | Active time per app. | `Σ APPLICATION durations grouped by app` |

**Why idle starts at the last input:** if the limit is 5 minutes, we only *know* the person was idle after 5 minutes. Those 5 minutes were already idle, so the idle session is back-dated to the last input. Otherwise every idle period would add 5 fake active minutes.

---

## 6. Tracking Algorithm (Rust engine)

### 6.1 States

```text
SIGNED_OUT ──login──► NOT_TRACKING ──start──► TRACKING ◄──resume── PAUSED
                          ▲                     │   │                 ▲
                          └────────stop─────────┘   └──────pause──────┘

TRACKING has two sub-states: ACTIVE (application session open) and IDLE (idle session open).
While TRACKING, lock/sleep → AWAY (no session open). Unlock/wake → back to TRACKING.
```

### 6.2 The loop (every 2 seconds while TRACKING)

Each tick reads:
- the **foreground app** (process name, friendly name, window title),
- **idle seconds** (time since last mouse/keyboard input),
- the **wall clock** time and a **monotonic** clock (a clock that doesn't jump when someone changes the system time).

Then, in this order:

1. **Gap check (sleep / freeze):** if more than 30 seconds passed since the last tick, the computer was asleep or frozen. Close the open session at the **last tick time**. Start a fresh session now.
2. **Clock check:** if wall-clock time moved more than 60 seconds differently from the monotonic clock since the last tick, someone changed the system time. Close the session using the monotonic duration, start a new one with the new wall time, and set `clockChanged = 1` on the new session.
3. **Idle check:**
   - ACTIVE and `idleSeconds ≥ idleLimit` → close the app session at `lastInputTime`. Open an IDLE session starting at `lastInputTime`, with `idleAppName` = current app.
   - IDLE and `idleSeconds < idleLimit` (person came back) → close the IDLE session at `lastInputTime`. Open an app session for the current app starting at `lastInputTime`.
4. **App switch check (ACTIVE only):** if the foreground app is different from the open session's app:
   - First time seen → remember it as a *candidate* with the time it was first seen.
   - Still in front on the next tick → close the current session at the candidate's first-seen time and open a new session for the candidate app.
   - Gone by the next tick → forget it. (This absorbs flicker from rapid Alt-Tab.)
   - Title changes inside the same app do **not** start a new session. The title of the open session is updated to the latest one seen.
5. **Chunk check:** if the open session is **10 minutes** long:
   - ACTIVE: close it at `lastInputTime` and open a new session for the same app starting at `lastInputTime`. (Cutting at the last input keeps back-dated idle exact.)
   - IDLE: close it at now and open a new IDLE session at now.
   - This makes sure a 3-hour VS Code session reaches the dashboard in 10-minute pieces instead of all at once at the end.
6. **Every 30 seconds:** write `lastSeenAt` on the open session row in SQLite (for crash recovery).

**Closing a session** means, in one SQLite transaction: set `ended_at`, set `duration_seconds`, set `sync_status = 'PENDING'`, and insert a row in `sync_queue`. Sessions shorter than 1 second are deleted instead of saved.

### 6.3 Other events

| Event | What the engine does |
|---|---|
| Start | Create a new session for the current app. Tell the server "tracking on this PC". |
| Pause | Close the open session now. State = PAUSED. No sessions while paused. |
| Resume | Same as Start. |
| Stop | Close the open session now. State = NOT_TRACKING. Trigger a sync. |
| Screen locked (`WTS_SESSION_LOCK`) | Close the open session now. State = AWAY. |
| Screen unlocked | If it was TRACKING before, start a new session. |
| Sleep (`PBT_APMSUSPEND`) | Close the open session now. Save to disk. State = AWAY. |
| Wake (`PBT_APMRESUMEAUTOMATIC`) | Wait for unlock (Windows usually locks on wake). Then resume. |
| Shutdown / log off (`WM_QUERYENDSESSION`) | Close the open session. Save. Remember "was tracking = yes". |
| App starts after crash/reboot | Any session with `ended_at IS NULL` gets `ended_at = last_seen_at`. If "was tracking" was yes, **resume tracking automatically** and show a notification "Tracking resumed". |
| Quit from tray | Ask "Stop tracking and quit?". Then Stop, try to sync (max 10 s), exit. |
| Logout | Stop tracking. Try to send everything (max 30 s). If it worked → sign out. If offline → show *"You're offline, your data will be sent next time you log in"*, sign out, keep the data. |

### 6.4 Windows details (in `platform/win.rs`)

| Situation | How to handle it |
|---|---|
| Get the front window | `GetForegroundWindow` |
| Get its process | `GetWindowThreadProcessId` → `OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION)` → `QueryFullProcessImageNameW` |
| Friendly name ("Visual Studio Code" instead of "Code.exe") | Read `FileDescription` with `GetFileVersionInfoW`. Fall back to the file name without `.exe`. Cache by exe path. |
| Window title | `GetWindowTextW`, trimmed, max 512 characters. |
| Idle seconds | `GetLastInputInfo` vs `GetTickCount` (use wrapping subtraction – the counter wraps every 49 days). |
| Store apps (Calculator, Settings) | The front process is `ApplicationFrameHost.exe`. Look through its child windows (`EnumChildWindows`) for one owned by a different process, and use that process. |
| Admin / protected windows | If `OpenProcess` fails, use app name **"Protected app"**. |
| No front window (UAC prompt, secure desktop) | App name **"Windows security prompt"**. |
| Desktop | `explorer.exe` with window class `Progman` or `WorkerW` → **"Desktop"**. Other Explorer windows → **"File Explorer"**. |
| Lock screen | `LockApp.exe` in front or lock event → AWAY. |
| Lock / sleep events | Create a hidden (never shown) top-level window on its own thread. Call `WTSRegisterSessionNotification`. Listen for `WM_WTSSESSION_CHANGE`, `WM_POWERBROADCAST` and `WM_ENDSESSION`. (A message-only window would miss power and shutdown messages.) |
| Multiple monitors | Only the **one** foreground window counts. A video on a second monitor that isn't focused is not tracked. |
| Full-screen apps / games | Nothing special. They are the foreground window. |
| Remote Desktop / VMs | Shows as the RDP/VM app only. Known limitation. |

**Future macOS/Linux support:** all Windows code sits behind a Rust trait:

```rust
pub trait ActivityProvider: Send + Sync {
    fn current_activity(&self) -> Option<ForegroundApp>; // None = no window
    fn idle_seconds(&self) -> u64;
}
pub trait SystemEvents { /* sends Lock, Unlock, Sleep, Wake, Shutdown into a channel */ }
```

The engine only uses these traits. A fake version is used in tests.

---

## 7. SQLite Design (on the employee's computer)

File: `%APPDATA%\<app-id>\tracker.db`. Settings: `PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;`.
All times are **UTC milliseconds** (integers).

### 7.1 `sessions`

```sql
CREATE TABLE sessions (
  id               TEXT PRIMARY KEY,         -- UUID v7, made on the PC. Also the sessions.id primary key on the server.
  user_id          TEXT NOT NULL,            -- Laravel users.id of the logged-in employee (sent as a string)
  device_id        TEXT NOT NULL,            -- this PC (UUID saved in app_state on first run)
  session_type     TEXT NOT NULL CHECK (session_type IN ('APPLICATION','IDLE')),
  app_name         TEXT,                     -- friendly name, e.g. "Visual Studio Code" (APPLICATION)
  process_name     TEXT,                     -- e.g. "Code.exe" (APPLICATION)
  window_title     TEXT,                     -- last title seen; may be NULL per office setting
  idle_app_name    TEXT,                     -- app in front while idle, e.g. "Zoom" (IDLE)
  started_at       INTEGER NOT NULL,
  ended_at         INTEGER,                  -- NULL while the session is open
  last_seen_at     INTEGER NOT NULL,         -- updated every 30 s; used after a crash
  duration_seconds INTEGER,                  -- from the monotonic clock, set on close
  clock_changed    INTEGER NOT NULL DEFAULT 0,
  sync_status      TEXT NOT NULL DEFAULT 'OPEN'
                   CHECK (sync_status IN ('OPEN','PENDING','SYNCED','REJECTED')),
  created_at       INTEGER NOT NULL
);
CREATE INDEX idx_sessions_user_started ON sessions (user_id, started_at);   -- "today" screens
CREATE INDEX idx_sessions_open ON sessions (ended_at) WHERE ended_at IS NULL; -- crash recovery
```

### 7.2 `sync_queue`

```sql
CREATE TABLE sync_queue (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,  -- also gives send order (oldest first)
  entity_type     TEXT NOT NULL,             -- 'SESSION' (only type for now)
  entity_id       TEXT NOT NULL UNIQUE,      -- sessions.id; UNIQUE stops double queueing
  user_id         TEXT NOT NULL,             -- only sent with this user's token
  payload         TEXT NOT NULL,             -- the exact JSON to send
  attempts        INTEGER NOT NULL DEFAULT 0,
  last_attempt_at INTEGER,
  next_attempt_at INTEGER NOT NULL,          -- for retry wait times
  status          TEXT NOT NULL DEFAULT 'PENDING'
                  CHECK (status IN ('PENDING','DONE','FAILED')),
  last_error      TEXT,
  created_at      INTEGER NOT NULL
);
CREATE INDEX idx_queue_ready ON sync_queue (user_id, status, next_attempt_at);
```

### 7.3 `app_state`

```sql
CREATE TABLE app_state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
-- keys: schema_version, device_id, current_user_id, was_tracking,
--       last_sync_at, last_sync_error, office_settings_json
```

### 7.4 Housekeeping

- On startup: `PRAGMA quick_check`. If it fails → rename the file to `tracker.db.corrupt-<timestamp>`, create a fresh database, write a log entry, show a notice, and report `dbReset: true` in the next sync.
- Before a migration: copy the file to `tracker.db.bak`. Run the migration in a transaction.
- Once a day: delete `SYNCED` sessions and `DONE` queue rows older than **7 days**.
- **Unsent data is never deleted automatically.**
- The login token is **not** stored in SQLite. It goes in Windows Credential Manager (`keyring` crate).

---

## 8. Database Design (MySQL)

There is only one office, so there is no `organizations` table. All times are stored in UTC (`TIMESTAMP` columns); Laravel converts to the office timezone for display. Table names are Laravel's default snake_case plurals; models are singular (`User`, `EmployeeStatus`, `Session`, `DailySummary`, `Device`, `OfficeSetting`, `AuditLog`).

```sql
CREATE TABLE users (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(255) NOT NULL,
  email                VARCHAR(255) NOT NULL UNIQUE,
  password             VARCHAR(255) NOT NULL,          -- Laravel hashed (bcrypt/argon2id)
  role                 ENUM('OIC','PROJECT_MANAGER','TEAM_LEADER','LEAD_DEVELOPER',
                             'DEVELOPER','CLIENT_SUPPORT','QA','SYSTEM_ANALYST') NOT NULL,
  manager_id           BIGINT UNSIGNED NULL REFERENCES users(id),  -- self-referencing; NULL only for OIC
  status               ENUM('ACTIVE','DEACTIVATED') NOT NULL DEFAULT 'ACTIVE',
  deactivated_at       TIMESTAMP NULL,
  consent_version      INT NULL,
  consent_accepted_at  TIMESTAMP NULL,
  created_by           BIGINT UNSIGNED NULL REFERENCES users(id),
  created_at, updated_at TIMESTAMP,                    -- Laravel timestamps
  INDEX idx_users_manager (manager_id)
);
```

**The hierarchy (§9.1):** `OIC` → `PROJECT_MANAGER` → `TEAM_LEADER` → (`LEAD_DEVELOPER`, `DEVELOPER`, `CLIENT_SUPPORT`, `QA`, `SYSTEM_ANALYST`). `manager_id` must point at a user exactly one level up — enforced in the `AdminEmployeeController@store` Form Request, not a database constraint (MySQL `CHECK` can't reference another row's column). There is exactly one `OIC` row with `manager_id = NULL`; every other row has a `manager_id`.

```sql
CREATE TABLE employee_statuses (                       -- one live-status row per employee
  user_id                BIGINT UNSIGNED PRIMARY KEY REFERENCES users(id),
  state                  ENUM('ACTIVE','IDLE','PAUSED','AWAY','NOT_TRACKING') NOT NULL,
  current_app            VARCHAR(128) NULL,
  idle_app_name          VARCHAR(128) NULL,
  device_id              CHAR(36) NULL,
  agent_version          VARCHAR(32) NULL,
  since                  TIMESTAMP NOT NULL,            -- when this state began
  last_seen_at           TIMESTAMP NOT NULL,             -- server time of last sync
  clock_skew_seconds     INT NOT NULL DEFAULT 0,         -- PC clock minus server clock
  tracking_device_since  TIMESTAMP NULL                  -- when this device took over tracking
);

CREATE TABLE sessions (                                 -- id = UUID made on the PC (idempotency key)
  id                CHAR(36) PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL REFERENCES users(id),
  device_id         CHAR(36) NOT NULL,
  type              ENUM('APPLICATION','IDLE') NOT NULL,
  app_name          VARCHAR(128) NULL,
  app_key           VARCHAR(128) NULL,
  process_name      VARCHAR(128) NULL,
  window_title      VARCHAR(512) NULL,
  idle_app_name     VARCHAR(128) NULL,
  started_at        TIMESTAMP(3) NOT NULL,
  ended_at          TIMESTAMP(3) NOT NULL,
  duration_seconds  INT NOT NULL,
  day               DATE NOT NULL,                       -- office-timezone day of started_at
  clock_changed     BOOLEAN NOT NULL DEFAULT 0,
  received_at       TIMESTAMP NOT NULL,
  INDEX idx_sessions_user_started (user_id, started_at),
  INDEX idx_sessions_day (day)
  -- retention: rows with started_at older than 30 days deleted by a scheduled job (§8 Housekeeping)
);

CREATE TABLE daily_summaries (
  user_id             BIGINT UNSIGNED NOT NULL REFERENCES users(id),
  day                 DATE NOT NULL,
  tracked_seconds     INT NOT NULL DEFAULT 0,
  active_seconds      INT NOT NULL DEFAULT 0,
  idle_seconds        INT NOT NULL DEFAULT 0,
  apps                JSON NOT NULL DEFAULT ('{}'),        -- { appKey: seconds } — active seconds per app
  app_names           JSON NOT NULL DEFAULT ('{}'),        -- { appKey: "Visual Studio Code" }
  first_activity_at   TIMESTAMP(3) NULL,
  last_activity_at    TIMESTAMP(3) NULL,
  PRIMARY KEY (user_id, day)
  -- retention: rows older than 90 days deleted by a scheduled job
);

CREATE TABLE devices (
  id              CHAR(36) PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL REFERENCES users(id),
  computer_name   VARCHAR(255) NULL,
  agent_version   VARCHAR(32) NULL,
  first_seen_at   TIMESTAMP NOT NULL,
  last_seen_at    TIMESTAMP NOT NULL
);

CREATE TABLE office_settings (                          -- single row, id = 1
  id                       TINYINT PRIMARY KEY DEFAULT 1,
  timezone                 VARCHAR(64) NOT NULL,         -- e.g. "Asia/Manila" — defines what "a day" is
  idle_threshold_seconds   INT NOT NULL DEFAULT 300,
  window_title_mode        ENUM('FULL','APP_ONLY') NOT NULL DEFAULT 'FULL',
  min_agent_version        VARCHAR(32) NOT NULL,
  consent_version          INT NOT NULL DEFAULT 1
);

CREATE TABLE audit_logs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id    BIGINT UNSIGNED NOT NULL REFERENCES users(id),
  action           VARCHAR(64) NOT NULL,
  target_user_id   BIGINT UNSIGNED NULL REFERENCES users(id),
  details          JSON NULL,
  created_at       TIMESTAMP NOT NULL,
  INDEX idx_audit_created (created_at DESC)
  -- retention: rows older than 365 days deleted by a scheduled job
);

-- Sanctum's own migration creates `personal_access_tokens` (tokenable_id/type, token hash, abilities, expires_at).
```

**`app_key`:** process name in lowercase, without `.exe`, with any character other than `a-z 0-9 _` replaced by `_` (e.g. `code`, `chrome`). Safe as a JSON object key.

**Retention (Laravel scheduler, replaces Firestore TTL):** an artisan command `php artisan tracker:prune` deletes `sessions` older than 30 days, `daily_summaries` older than 90 days, and `audit_logs` older than 365 days. Registered in `routes/console.php` (`Schedule::command('tracker:prune')->daily()`) and driven by one cron entry (`* * * * * php artisan schedule:run`) set up on the office server per `docs/SETUP.md`. Unlike Firestore TTL (best-effort, "usually within a day"), this runs on a known schedule and is easy to test directly (Test 4.5-equivalent: run the command, assert old rows are gone).

**Database access:** MySQL listens only on `localhost` (or the private network if the DB is a separate host) — no public port. Only the Laravel app's DB user connects, with a password from `.env` (never committed). The desktop app and dashboard never get direct database credentials.

**Idempotency without Firestore's "document must not exist" precondition:** `sessions.id` is a `PRIMARY KEY`. Laravel checks which of the incoming UUIDs already exist (`whereIn('id', $ids)->pluck('id')`) inside a DB transaction, inserts only the new ones, and treats a duplicate-key error on insert as a second safety net (race between two concurrent syncs). See §10.2.

---

## 9. Authentication and Authorization

### 9.1 Roles and hierarchy

Every account has exactly one role, and (except the OIC) exactly one manager, forming a 4-level tree:

```text
OIC
 └─ PROJECT_MANAGER (one or more)
     └─ TEAM_LEADER (one or more per PM)
         └─ LEAD_DEVELOPER, DEVELOPER, CLIENT_SUPPORT, QA, SYSTEM_ANALYST (any number per Team Leader)
```

Everyone — every role, including the OIC — logs into the **desktop app** the same way and tracks their own time the same way. What differs by role is **dashboard access** and **whose data you can see there**:

| Role tier | Roles | Dashboard access | Can see |
|---|---|---|---|
| **Manager roles** | `OIC`, `PROJECT_MANAGER`, `TEAM_LEADER` | Yes | Their own data, **plus** everyone below them in the tree (their direct reports and all of *those* reports' reports, recursively). An OIC sees the whole office; a Team Leader sees just their own team. |
| **Individual-contributor roles** | `LEAD_DEVELOPER`, `DEVELOPER`, `CLIENT_SUPPORT`, `QA`, `SYSTEM_ANALYST` | No | Only their own data (in the desktop app's own Today/history screens — same as the old "Employee" behavior). |

Two things are **manager-role-wide but not hierarchy-scoped**, i.e. OIC-only rather than "any manager who can see that data": **office settings** (idle threshold, timezone, window title mode, minimum agent version, consent version) and the **audit log**. These are organization-wide, not per-team, so splitting them by hierarchy isn't worth the complexity yet — a Team Leader doesn't get a settings screen or an audit view, even for their own team. (Team-scoped audit/settings would be a reasonable later addition if it's ever needed — not in the MVP.)

**Creating accounts:** a manager creates an account **one level below their own role**, as their own direct report (`manager_id` = the creator's id): an OIC creates Project Managers, a Project Manager creates Team Leaders, a Team Leader creates any of the five individual-contributor roles. Nobody creates an account two or more levels below themselves directly — an OIC doesn't hand-create a Developer; the Developer's Team Leader does. This keeps the tree's shape self-enforcing instead of needing separate validation for "is this a sane org chart."

**Visibility check, in code terms:** `User::visibleTo(User $viewer): bool` is true when `target.id === viewer.id`, or when `viewer` holds a manager role and `target.id` is in `viewer.allDescendantIds()` (computed by loading `id, manager_id` for the whole `users` table — small, one office — and walking the tree in PHP; see `HierarchyService`). The same descendant set gates the employee list, summary/timeline access, and who a manager is allowed to deactivate or edit.

### 9.2 How login works

- **Desktop app:** the Vue login screen sends email + password to Rust (`invoke("login")`). Rust calls `POST /api/v1/auth/login` on the Laravel API. Laravel checks the password (`Hash::check`) and, if it's correct and the user is `ACTIVE`, issues a **Sanctum personal access token** (`$user->createToken('agent-<deviceId>', ['agent'])`, expiring per `sanctum.expiration` — e.g. 30 days). Rust keeps that **token in Windows Credential Manager** and sends it as `Authorization: Bearer <token>` on every request. The Vue screens never hold the token. There is no separate "ID token" / "refresh token" split like Firebase had — the Sanctum token *is* the credential, and Laravel checks the user's live `status` on every request, so a deactivation takes effect immediately without any token-refresh dance.
- **Dashboard:** Sanctum's **SPA authentication** (session cookie + CSRF, not a bearer token) — this works because the dashboard is served from the same domain as the API (§2). Login posts to `/login`; Laravel sets a session cookie; subsequent `/api/v1/...` calls are authenticated by that cookie automatically. An individual-contributor account can technically log in (correct password), but every dashboard route then 403s per §9.1 — the login page itself shows "This dashboard is for managers only."
- **Forgot password:** "Forgot password" button → Laravel's built-in password-reset flow (`Password::sendResetLink`), emailed via the SMTP relay configured in `.env` (see the Email row in §3 — **TODO** until a relay is chosen).
- **First OIC (one-time, manual):** run `php artisan tracker:make-oic "Name" email@office.com` (a small custom artisan command we write in Phase 2) — it creates the `users` row directly with `role = 'OIC'`, `manager_id = NULL`, `status = 'ACTIVE'`, and a temporary password printed to the console (or an emailed reset link, if mail is configured). Write these steps in `docs/SETUP.md`. Every other account is created through the normal manager-creates-a-direct-report flow, starting from this one OIC.
- **Adding accounts:** a manager enters a name + email in the dashboard and picks a role — restricted by the UI (and re-checked server-side) to the one role tier below their own. Laravel creates the `users` row with a random unusable password and `manager_id` = the creator, then sends a password-reset email so the new person sets their own password (same mechanism as "Forgot password"). If mail isn't configured yet, the dashboard shows the reset link directly so the manager can send it manually.

### 9.3 What the Laravel API checks on every request

1. `Authorization: Bearer <Sanctum token>` header (agent) or a valid session cookie + CSRF token (dashboard).
2. Sanctum resolves the token/cookie to a `User` via its own `personal_access_tokens` table (hashed lookup) or the session — built into the framework, no manual signature/JWKS handling needed.
3. Token `expires_at` (if set) is in the future; otherwise `401`.
4. Middleware `EnsureActiveUser` loads the authenticated user and requires `status = 'ACTIVE'` (with one exception for unsent data, see §10) — checked on every request, not cached, since it's a single indexed lookup on the same DB the request is already touching.
5. Role/hierarchy check for the route: `EnsureManager` (any of the three manager roles — gates dashboard routes generally), `EnsureOic` (settings, audit), or `EnsureSelfOrVisible($paramId)` (self, or `$paramId` is in the viewer's descendant set per §9.1) for the per-employee routes.
6. **The user id always comes from the authenticated session/token**, never the request body. Any `uid`, `userId`, `role` or `employeeId` field in the body is ignored by the Form Request's validation rules (not just unused — it's not even a recognized field). For `/employees/{id}/...` routes: a manager → any id in their descendant set. Anyone → their own id. Otherwise `403`.

---

## 10. API Design (Laravel)

Base URL: `https://<your-domain>/api/v1`. All responses are JSON. Errors look like `{ "error": { "code": "FORBIDDEN", "message": "..." } }` (a custom exception renderer in `app/Exceptions/Handler.php` maps Laravel's default error shapes to this format).

| Method & path | Who | Purpose | Laravel piece |
|---|---|---|---|
| `GET /health` | anyone | `{ ok: true, version }` | plain route, no controller |
| `POST /api/v1/auth/login` | anyone | Email + password → Sanctum token (agent) | `AuthController@login` |
| `GET /api/v1/me` | logged in | Own profile, role, office settings, whether consent is needed | `MeController@show` |
| `POST /api/v1/me/consent` | logged in | Record consent `{ consentVersion }` | `MeController@acceptConsent` |
| `POST /api/v1/agent/sync` | logged in (agent) | Sends status + up to 100 closed sessions. Gets back results + commands. | `AgentController@sync` |
| `GET /api/v1/employees` | manager | List of people in the caller's hierarchy (§9.1) with live status and today's totals | `EmployeeController@index` |
| `GET /api/v1/employees/{id}/summary?from=YYYY-MM-DD&to=YYYY-MM-DD` | self, or a manager whose hierarchy includes `{id}` | Daily totals per day (max 31 days) | `EmployeeController@summary` |
| `GET /api/v1/employees/{id}/timeline?day=YYYY-MM-DD&cursor=` | self, or a manager whose hierarchy includes `{id}` | Merged timeline segments for one day (max 500 per page) | `EmployeeController@timeline` |
| `POST /api/v1/admin/employees` | manager | Create a direct report `{ name, email, role }` — role must be exactly one tier below the caller's (§9.1) | `AdminEmployeeController@store` |
| `PATCH /api/v1/admin/employees/{id}` | a manager whose hierarchy includes `{id}` | Change `name` or `status` (deactivate / reactivate); changing `role`/`manager_id` (re-parenting) is restricted to the direct manager only | `AdminEmployeeController@update` |
| `GET /api/v1/admin/settings` / `PUT` | OIC only | Read / change office settings | `AdminSettingsController` |
| `GET /api/v1/admin/audit?cursor=` | OIC only | Audit log, newest first, 50 per page | `AdminAuditController@index` |

**Why one `/agent/sync` endpoint instead of separate "sessions" and "batch" endpoints:** the app sends one request every 2 minutes carrying both its live status and any new sessions. One request instead of two halves the traffic and the number of DB round-trips. A single session is just a batch of one.

### 10.1 `POST /api/v1/agent/sync`

Request headers: `Authorization`, `X-Agent-Version: 1.2.0`, `X-Device-Id: <uuid>`.

```json
{
  "clientTime": "2026-09-24T09:10:00.000Z",
  "computerName": "JUAN-LAPTOP",
  "dbReset": false,
  "status": {
    "state": "ACTIVE",
    "currentApp": "Visual Studio Code",
    "idleAppName": null,
    "since": "2026-09-24T09:00:00.000Z",
    "trackingStartedAt": "2026-09-24T08:02:00.000Z"
  },
  "sessions": [
    {
      "id": "01926a3e-7f0a-7cc1-9a51-3c1f2b7d9e10",
      "type": "APPLICATION",
      "appName": "Visual Studio Code",
      "processName": "Code.exe",
      "windowTitle": "time-tracker - Visual Studio Code",
      "idleAppName": null,
      "startedAt": "2026-09-24T09:00:00.000Z",
      "endedAt": "2026-09-24T09:10:00.000Z",
      "durationSeconds": 600,
      "clockChanged": false
    }
  ]
}
```

Response `200`:

```json
{
  "accepted": ["01926a3e-..."],
  "duplicates": [],
  "rejected": [{ "id": "...", "reason": "TOO_OLD" }],
  "serverTime": "2026-09-24T09:10:01.200Z",
  "commands": { "stopTracking": false, "stopReason": null, "signOut": false },
  "settings": { "idleThresholdSeconds": 300, "windowTitleMode": "FULL" }
}
```

Other responses: `401` (token bad/expired – app shows "Please log in again"), `403 ACCOUNT_DEACTIVATED`, `426 UPGRADE_REQUIRED`, `429` (too many requests – wait), `5xx` (retry later).

**Validation (a Laravel Form Request, `AgentSyncRequest`)** — a session is **rejected** (not retried) if:
- `id` is not a UUID, or `type` is not one of the two values;
- `endedAt ≤ startedAt`, or `durationSeconds` is not 1–660, or it differs from `endedAt − startedAt` by more than 5 seconds;
- `endedAt` is more than 10 minutes in the future (server time);
- `startedAt` is older than 30 days (`TOO_OLD`);
- text fields are too long (`appName`/`processName` 128, `windowTitle` 512).
- more than 100 sessions in the request → the whole request gets `400` (the Form Request's top-level rule).

**What `AgentController@sync` does, step by step**
1. Middleware already checked the auth token, rate limit, and agent version (`426` if below `office_settings.min_agent_version`).
2. `AgentSyncRequest` validates the body; invalid → `422`/`400` before the controller runs.
3. If `office_settings.window_title_mode = APP_ONLY`, set every `windowTitle` to `null`.
4. `DB::transaction()` (wraps everything below; MySQL's row locks stand in for Firestore's transaction):
   1. `Session::whereIn('id', $ids)->lockForUpdate()->pluck('id')` — sessions that already exist → `duplicates`. New ones → `accepted`.
   2. **One tracking PC rule:** load `employee_statuses` for this user with `lockForUpdate()`. If `state` is ACTIVE/IDLE and `device_id` is a different PC that synced in the last 5 minutes → this PC takes over (`device_id` = this one, `tracking_device_since` = now). The old PC gets `commands.stopTracking = true, stopReason: "STARTED_ON_OTHER_PC"` on its next sync, and its sessions starting after `tracking_device_since` are rejected (`OTHER_DEVICE_ACTIVE`).
   3. **Deactivated user:** sessions that started before `deactivated_at` are accepted. Newer ones are rejected. Respond with `commands.signOut = true`.
   4. Bulk-`insert()` the new sessions (catch a duplicate-key `QueryException` per row as a second safety net — see §10.2), upsert the `employee_statuses` row, and fold each new session into its daily summary (see below).
   5. A duplicate-key exception on insert means someone else's request beat this one to that row — treat it the same as step 4.1 finding it already existed (move it from `accepted` to `duplicates`) rather than failing the whole batch.
5. Return the response.

**Adding a session into daily summaries (`SummaryService`):**
- Work out which office-timezone day(s) the session covers. If it crosses midnight, split the seconds between the two days.
- For each day, upsert `daily_summaries` (composite key `user_id, day`) inside the same transaction, with the row locked (`lockForUpdate()`):
  - add to `tracked_seconds`, plus `active_seconds` **or** `idle_seconds`;
  - APPLICATION: read-modify-write the `apps` JSON column (`apps[appKey] += seconds`) and set `app_names[appKey]`;
  - `first_activity_at = LEAST(first_activity_at, new value)`, `last_activity_at = GREATEST(...)` (plain SQL `LEAST`/`GREATEST`, MySQL's equivalent of Firestore's `minimum`/`maximum` transforms).
- Because only **new** sessions are folded in (step 4.1), a repeated upload never counts twice.

### 10.2 Idempotency (no duplicates) — summary

1. The PC creates a UUID for every session. The UUID never changes, even across retries.
2. That UUID is the `sessions.id` primary key on the server.
3. Inside a DB transaction, Laravel checks which UUIDs already exist (row-locked) and inserts only the missing ones; only those are folded into the totals.
4. Duplicates are reported back as `duplicates`. The app treats them like `accepted` (mark as sent).
5. The primary key itself is the second safety net: a race that slips past the existence check still can't insert two rows with the same id — the loser's insert raises a duplicate-key error, which the controller catches and reclassifies as a duplicate instead of an error.

### 10.3 Other Laravel details

- **Rate limiting:** Laravel's built-in `throttle` middleware, keyed by user id (`RateLimiter::for('agent-sync', fn ($request) => Limit::perMinute(30)->by($request->user()->id))` in `AppServiceProvider`). `/agent/sync`: 30 requests per minute. Other routes: 120 per minute.
- **CORS:** not needed for the normal case — dashboard and API are same-origin (§2). Laravel's `config/cors.php` stays locked down (no origins allowed) unless a future need (e.g. a separate marketing site) requires opening it up.
- **Timeline (`TimelineService`):** query `sessions` where `user_id = id`, `started_at ≥ dayStart − 11 min`, `started_at < dayEnd`, ordered by `started_at`. Clip to the day. Merge neighbouring pieces that have the same type and app and a gap of 5 seconds or less. Return segments `{ type, label, startedAt, endedAt, seconds }`, where `label` is e.g. `"Visual Studio Code"` or `"Idle (in Zoom)"`.
- **Employee list:** `User::whereIn('id', $viewer->allDescendantIds())` (office size, so no pagination needed) with `with('employeeStatus')` and today's `daily_summaries` eager-loaded (two extra indexed queries, not N+1) — `allDescendantIds()` is the same `HierarchyService` walk used for the visibility check (§9.1), so the list and the per-employee 403 checks can never disagree about who's visible. Show "Offline" if `last_seen_at` is more than 5 minutes ago while the state says tracking.
- **Audit log:** write an entry (via an `AuditLog::record(...)` helper, or a Laravel event listener on employee-changed events) when a manager creates, changes or deactivates an account, an OIC changes settings, or a manager opens someone's timeline. Only the OIC reads the log (§9.1); it isn't filtered by hierarchy since only one role ever sees it.
- **Logging:** Laravel's default structured logging (`storage/logs/laravel.log`, or forward to syslog/journald) via the `Log` facade. Never log tokens, password hashes, or full window titles.

---

## 11. Desktop App ↔ Rust Interface

All in `commands.rs`. The Vue side wraps them in composables (`useTracking`, `useSync`). Types live in `packages/shared` (TS) and are mirrored in Rust structs with `#[serde(rename_all = "camelCase")]`.

| Command | Returns |
|---|---|
| `login(email, password)` | `Me` or an error `{ code: "WRONG_PASSWORD" \| "OFFLINE" \| "DEACTIVATED" }` |
| `logout()` | `{ synced: boolean, pendingCount: number }` |
| `get_me()` | `Me \| null` |
| `accept_consent()` | `void` |
| `start_tracking()` / `pause_tracking()` / `resume_tracking()` / `stop_tracking()` | `TrackingState` |
| `get_tracking_state()` | `{ state, since, currentSessionStartedAt }` |
| `get_current_activity()` | `{ application, processName, windowTitle, idleSeconds }` |
| `get_today_summary()` | `{ trackedSeconds, activeSeconds, idleSeconds, apps: [{ name, seconds }] }` (from local SQLite) |
| `get_today_timeline()` | `Segment[]` (from local SQLite) |
| `get_sync_status()` | `{ pendingCount, lastSyncAt, lastError, online }` |
| `set_launch_at_startup(enabled)` | `void` |

Example:

```ts
const activity = await invoke<CurrentActivity>("get_current_activity")
// { application: "Visual Studio Code", processName: "Code.exe",
//   windowTitle: "time-tracker - Visual Studio Code", idleSeconds: 0 }
```

**Events from Rust to Vue** (so the screen updates without polling): `tracking-state-changed`, `activity-changed`, `sync-status-changed`, `notice` (e.g. "Tracking was started on another computer").

### 11.1 Sync loop (`sync/worker.rs`)

- Runs every **2 minutes** while logged in, also right after Stop, and when the internet comes back.
- Takes up to 100 `PENDING` queue rows for the current user where `next_attempt_at ≤ now`, oldest first, and sends them with the current status.
- `accepted` / `duplicates` → queue row `DONE`, session `SYNCED`.
- `rejected` → queue row `FAILED`, session `REJECTED`, reason logged.
- If a full batch of 100 succeeded, send the next batch straight away (up to 20 batches per cycle). This clears a big backlog after being offline.
- Network error / `5xx` / `429` → wait and retry: 1, 2, 5, 10, then 30 minutes max. **Tracking is never affected.**
- `401` → the Sanctum token is invalid or expired (no refresh step, unlike Firebase's ID/refresh token pair — see §9.2). Show "Please log in again" and keep the data.
- `426` → show "Please update the app" and check for updates. Keep tracking locally.
- `commands.stopTracking` → stop and show the reason. `commands.signOut` → sign out (after the sync).
- Save `settings` from the response into `app_state`.
- Status-only syncs (no sessions) still run every 2 minutes while tracking, so the dashboard shows live status.

---

## 12. Development Phases

Each phase has **Tasks**, **Deliverables** and **Tests**.
Test tags: **[N]** normal · **[F]** failure · **[S]** security · **[D]** data integrity · **[P]** performance.

---

### Phase 0 — Technical Spike (Windows)

**Goal:** prove the risky parts work on Windows before building anything else. The code is built inside `apps/agent` so it can be kept.

**Tasks**
1. Create a Tauri 2 app with Nuxt 4 (`ssr: false`, static generation) in `apps/agent`.
2. Add the Rust command `get_current_activity` using the Win32 calls in §6.4 (foreground window, process, friendly name, title, idle seconds).
3. Handle the special cases: Store apps (`ApplicationFrameHost`), protected apps, no window, Desktop / File Explorer.
4. Add a hidden top-level window that logs lock/unlock/sleep/wake/shutdown events.
5. Add a background task (tokio) that every 2 seconds logs the current activity to a text file with timestamps, even when the window is hidden.
6. Add a tray icon with **Show** / **Quit**. Closing the window hides it; it doesn't quit.
7. Add `tauri-plugin-single-instance`.
8. A basic Vue page that calls `get_current_activity` every second and shows the result.
9. Measure CPU and memory with the window hidden.
10. Write down the results in `docs/spike-results.md` (what worked, surprises, numbers).

**Deliverables**
- A Windows `.exe` (dev build) that shows the live app, title and idle seconds, and logs in the background.
- `docs/spike-results.md`.

**Tests**

```text
Test 0.1 [N] App launches
1. Run `pnpm tauri dev` on Windows.
Expected: window opens and shows the Vue page.
PASS: window opens with no errors in the console.

Test 0.2 [N] Rust ↔ Vue talk
1. Open the app. Look at the activity box.
Expected: it shows the app name, title and idle seconds, updating every second.
PASS: values change when you click different windows.

Test 0.3 [N] Front app detection
1. Click VS Code, then Chrome, then Notepad, then File Explorer, then the Desktop.
Expected: names shown: "Visual Studio Code", "Google Chrome", "Notepad", "File Explorer", "Desktop".
PASS: all 5 correct.

Test 0.4 [N] Store apps
1. Open Calculator and Settings.
Expected: shows "Calculator" / "Settings", NOT "ApplicationFrameHost".
PASS: both correct.

Test 0.5 [N] Window title
1. Open two Chrome tabs with different pages and switch between them.
Expected: title changes to match the tab.
PASS: title always matches.

Test 0.6 [N] Idle detection
1. Don't touch mouse/keyboard for 60 seconds.
2. Move the mouse.
Expected: idle seconds count up to ~60, then drop to 0.
PASS: count is within ±3 seconds of a stopwatch.

Test 0.7 [N] Background running
1. Hide the window (close button → goes to tray).
2. Use other apps for 10 minutes.
3. Open the log file.
Expected: a log line every ~2 seconds for the whole 10 minutes, with the correct apps.
PASS: no gaps longer than 5 seconds.

Test 0.8 [N] Lock / sleep events
1. Press Win+L, wait 30 s, unlock.
2. Put the PC to sleep, wait 1 min, wake it.
Expected: the log shows LOCK, UNLOCK, SLEEP, WAKE with correct times.
PASS: all 4 events logged.

Test 0.9 [F] Special windows
1. Open Task Manager as admin. Trigger a UAC prompt (e.g. run something as admin).
Expected: shows "Protected app" / "Windows security prompt". The app does not crash.
PASS: no crash, sensible names.

Test 0.10 [N] Single instance
1. Start the app twice.
Expected: the second start just shows the first window.
PASS: only one copy in Task Manager.

Test 0.11 [P] Resource use
1. Hide the window. Leave it running for 30 minutes.
2. Check Task Manager.
Expected: CPU mostly 0%, under 1% on average. Memory under 150 MB total (all app processes, including WebView2).
PASS: within those numbers.
```

**Exit rule:** all tests pass → continue. If something can't work (e.g. idle detection), stop and rethink before Phase 1.

---

### Phase 1 — Project Foundation

**Tasks**
1. Create the monorepo (§4): `pnpm-workspace.yaml`, root `package.json` scripts: `dev:agent`, `dev:dashboard`, `lint`, `typecheck`.
2. Move the spike into the final `apps/agent` structure.
3. Create `apps/dashboard` (Nuxt 4, `ssr: false`, Tailwind).
4. Create `apps/api` (`composer create-project laravel/laravel`). Install Sanctum (`composer require laravel/sanctum`). Add `GET /health`.
5. Create `packages/shared` with the TS types from §10 (request/response shapes — no zod; validation now lives in Laravel Form Requests, see §10.1).
6. TypeScript `strict: true` everywhere. `@nuxt/eslint` (stylistic mode) in `apps/agent` and `apps/dashboard`; a matching flat ESLint config in `packages/shared`. One tool doing both linting and formatting — no separate Prettier, so there's nothing for the two to disagree about.
7. Rust: `cargo fmt`, `cargo clippy -- -D warnings`. PHP: Laravel Pint (`vendor/bin/pint`).
8. Set up **two databases**: `tracker_dev` and `tracker_prod` (MySQL 8/MariaDB), either local for dev or both on the office server. Run `php artisan migrate` to create the schema from §8.
9. Add `office_settings` seeder with defaults (timezone, idle 300 s, `FULL` titles, `min_agent_version`, `consent_version` = 1); run `php artisan db:seed`.
10. **Office server setup** (see `docs/SETUP.md` for the full walkthrough): a Linux VM/box reachable on the internet under a domain (or subdomain) you control; nginx as reverse proxy + static file server; PHP-FPM; MySQL bound to `localhost`; a Let's Encrypt certificate (`certbot`) so the API and dashboard are HTTPS-only, matching the "public domain + reverse proxy" decision in §0.
11. Config:
    - agent build: `API_BASE_URL` (e.g. `https://tracker.example.com/api/v1`) — no other public keys needed, since Sanctum has no client-side SDK key;
    - dashboard: `NUXT_PUBLIC_API_BASE`;
    - API `.env`: `APP_KEY` (generated by `php artisan key:generate`, never committed), `DB_*`, `MAIL_*` (**TODO placeholder** — no SMTP relay chosen yet, see §3), `SANCTUM_STATEFUL_DOMAINS` (the dashboard's domain).
    - Add `.env.example` files. Put `.env*` (except examples) in `.gitignore`.
12. No CI service — `pnpm lint`, `pnpm typecheck`, `cargo fmt --check`, `cargo clippy -- -D warnings`, `cargo test`, `vendor/bin/pint --test` and `php artisan test` are run locally before pushing (Test 1.2). Revisit this if the team grows enough that "remember to run it" stops being reliable.
13. **Start getting a Windows code-signing certificate now** (it can take weeks). Options: Azure Trusted Signing (cheapest, if the company qualifies) or an OV certificate from a certificate authority.
14. Write `docs/SETUP.md`: how to install, run and deploy each part, including the office server provisioning steps from task 10.

**Deliverables**
- A repo where `pnpm install && pnpm lint && pnpm typecheck && pnpm test` pass, and `apps/api`'s `composer install && php artisan test` passes.
- The Laravel API is deployed to the office server (or a dev subdomain) and `/health` works over HTTPS.
- The dashboard runs locally and shows a placeholder page.

**Tests**

```text
Test 1.1 [N] Fresh setup
1. Clone the repo into a new folder. Follow docs/SETUP.md.
Expected: everything installs and runs (JS apps via pnpm, API via composer + artisan).
PASS: all three apps start without undocumented steps.

Test 1.2 [N] Quality checks
1. Run `pnpm lint`, `pnpm typecheck`, `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings`, `cargo test`, `vendor/bin/pint --test`, `php artisan test`.
Expected: all pass.
PASS: 0 errors.

Test 1.3 [N] API health
1. Open https://<dev-domain>/health.
Expected: {"ok":true,...}, valid HTTPS certificate (no browser warning).
PASS: 200 response, TLS padlock shown.

Test 1.4 [S] No secrets in git
1. Run `git grep -i "APP_KEY=base64\|DB_PASSWORD=\|BEGIN PRIVATE"`.
Expected: no results (except docs/.env.example mentioning the variable names with placeholder values).
PASS: no real secrets in the repo.

Test 1.5 [S] Database is not reachable from outside
1. From a machine that is not the office server, try to connect to the MySQL port (3306) on the server's public IP.
Expected: connection refused/times out.
PASS: only the API can reach the database.

```

---

### Phase 2 — Login, Users and Roles

**Tasks**
1. `composer require laravel/sanctum`, publish its config/migration, run `php artisan migrate`.
2. `config/sanctum.php`: set `expiration` (e.g. 30 days) for agent tokens; `SANCTUM_STATEFUL_DOMAINS` for the dashboard's cookie-based SPA auth (§9.2).
3. Migration: add `role`, `manager_id`, `status`, `deactivated_at`, `consent_version`, `consent_accepted_at`, `created_by` to `users` (§8).
4. `app/Services/HierarchyService.php`: loads `id, manager_id` for all users once per request and exposes `allDescendantIds(User $of): array` and `isManagerRole(User $user): bool` (§9.1). Used everywhere a visibility or "can I manage this account" check is needed, so the list endpoint and the per-record 403 checks can never disagree.
5. Middleware `EnsureActiveUser`: after Sanctum resolves the user, require `status = 'ACTIVE'`. `401` if unauthenticated, `403 ACCOUNT_DEACTIVATED` if deactivated.
6. Middleware `EnsureManager` (any manager role), `EnsureOic` (settings, audit), and `EnsureSelfOrVisible($paramId)` (self, or `$paramId` in `HierarchyService::allDescendantIds()`).
7. Routes: `POST /api/v1/auth/login`, `GET /api/v1/me`, `POST /api/v1/me/consent`, `POST/PATCH /api/v1/admin/employees`, `GET/PUT /api/v1/admin/settings`, `GET /api/v1/admin/audit`.
8. Create account (`AdminEmployeeController@store`): validate the requested `role` is exactly one tier below the caller's own (§9.1) — otherwise `422`; create the `users` row (`manager_id` = caller) → audit log entry. If the email already exists → `409`. **Built as:** a real random temporary password, returned once in the response for the manager to hand over directly — `Password::sendResetLink()` needs a working reset-password page on the dashboard, which doesn't exist yet. Swap this for the emailed-link flow once that page is built; the interim behavior is otherwise equivalent (a one-time credential only the manager sees).
9. Deactivate: set `status = DEACTIVATED`, `deactivated_at`, and revoke all their tokens (`$user->tokens()->delete()`) so they can't keep using an already-issued agent token. Reactivate does the reverse (no need to reissue a token — they log in again). Both gated by `EnsureSelfOrVisible` — actually here it's *not* self, just visible — a manager can deactivate anyone in their descendant set, at any depth, not only direct reports.
10. A manager cannot deactivate themselves, and the system refuses to leave zero `ACTIVE` `OIC` rows (`400`) — the OIC-equivalent of "last admin protection."
11. `php artisan tracker:make-oic "Name" email@office.com` — the one-time console command that creates the first account directly as OIC (§9.2), since there's no dashboard yet to do it from. Every other account descends from this one through the normal create-a-direct-report flow.
12. Dashboard: login page (posts to `/login`, Sanctum SPA cookie auth — no client SDK needed). After login call `/api/v1/me`. If the role isn't a manager role → sign out and show "This dashboard is for managers only." Route guard (Nuxt middleware) on every page.
13. Dashboard: `employees/manage` page — list of the caller's visible accounts, add a direct report (role dropdown limited to the one tier below the caller), deactivate / reactivate.
14. Desktop app: login screen → `invoke("login")` → Rust calls `POST /api/v1/auth/login` → Sanctum token saved in Credential Manager → `/api/v1/me`.
15. Desktop app: **consent screen** on first login (and whenever `consent_version` goes up). It lists exactly what is tracked (§16). Tracking can't start until it's accepted.
16. Desktop app: "Forgot password" link (opens the dashboard's password-reset page in the system browser, since the desktop app has no mail-sending of its own).
17. `docs/SETUP.md`: the one-time steps to create the first OIC (`php artisan tracker:make-oic`) and the SMTP `.env` placeholder to fill in once a mail relay is chosen.
18. Laravel feature tests (Pest/PHPUnit) for: login (correct/wrong password, deactivated user), `HierarchyService` (descendant sets at every tier, an individual contributor's empty descendant set), role-creation-one-tier-below enforcement, self-or-visible checks, token revocation on deactivate, last-OIC protection.

**Deliverables**
- An OIC can log in to the dashboard, create a Project Manager, who creates a Team Leader, who creates individual contributors — the whole hierarchy buildable from the dashboard after the one-time `tracker:make-oic` step.
- New accounts get an email (once mail is configured — otherwise the creating manager shares the reset link manually), set a password, log in to the desktop app and accept consent.

**Tests**

```text
Test 2.1 [N] OIC login
1. Run tracker:make-oic, then log in to the dashboard as that account.
Expected: you see the dashboard.
PASS: logged in.

Test 2.2 [N] Build the hierarchy
1. As OIC, create a Project Manager. Log in as them; create a Team Leader.
   Log in as the Team Leader; create a Developer.
2. Note the temporary password shown in the dashboard for each account created.
3. Log in to the desktop app with the Developer account, using that temporary password.
Expected: every step works; the desktop login shows the consent screen.
PASS: full 4-level chain works end to end.

Test 2.3 [S] Individual contributor can't use the dashboard
1. Log in to the dashboard as the Developer from Test 2.2.
Expected: "This dashboard is for managers only." and signed out.
PASS: no data visible.

Test 2.4 [S] Individual contributor can't call manager APIs
1. As the Developer, grab the Sanctum token from the desktop app's dev log (or log in via curl: `curl -X POST <api>/api/v1/auth/login -d '{"email":...,"password":...}'`).
2. curl -H "Authorization: Bearer <token>" <api>/api/v1/employees
3. curl the same token to POST /api/v1/admin/employees
Expected: 403 for both.
PASS: both 403.

Test 2.5 [S] Hierarchy scoping
1. Create two separate Team Leaders (A and B) under the same PM, each with their own Developer.
2. With Team Leader A's token: GET /api/v1/employees/<B's Developer id>/summary?from=...&to=...
Expected: 403 (not in A's descendant set).
3. With the shared PM's token, the same request:
Expected: 200 (both Team Leaders and their Developers are in the PM's descendant set).
PASS: A blocked, PM allowed.

Test 2.6 [S] Can only create one tier below yourself
1. As a Team Leader, POST /api/v1/admin/employees with role "PROJECT_MANAGER".
2. As a Team Leader, POST /api/v1/admin/employees with role "DEVELOPER".
Expected: first request 422 (not one tier below Team Leader); second 201.
PASS: only the correct tier is accepted.

Test 2.7 [S] Fake role/manager in the body is ignored
1. With a Developer's token, PATCH /api/v1/admin/employees/<own id> with {"role":"OIC","managerId":null}.
Expected: 403 (Developer isn't in their own descendant set as a manager — actually not visible-as-manager at all; this route requires a manager role). Role and manager_id unchanged in the database.
PASS: no change.

Test 2.8 [S] Bad tokens
1. Call /api/v1/me with no token, a random string, an expired token, and a token that was already revoked (deactivated user's old token).
Expected: 401 each time.
PASS: all 401.

Test 2.9 [N] Deactivate
1. A Team Leader deactivates one of their Developers.
2. That Developer tries to log in to the desktop app.
Expected: login fails with "Your account is deactivated."
PASS: can't log in.

Test 2.10 [N] Consent required
1. New account logs in. Try to start tracking without accepting.
Expected: impossible. Start is only available after accepting.
PASS: consent_accepted_at is saved on the users row after accepting.

Test 2.11 [S] Token not stored in plain files
1. After login, search %APPDATA% for the Sanctum token text.
Expected: not found. It's in Windows Credential Manager.
PASS: not in any file.

Test 2.12 [N] Last OIC protection
1. As the only OIC, try to deactivate yourself.
Expected: error, nothing changes.
PASS: still ACTIVE.

Test 2.13 [S] Settings and audit are OIC-only
1. As a Project Manager (not OIC), GET /api/v1/admin/settings and GET /api/v1/admin/audit.
Expected: 403 for both, even though a Project Manager is a manager role.
PASS: both 403.

Test 2.14 [N] Audit
1. As OIC, open Dashboard → Audit.
Expected: entries for every account created/deactivated across the whole hierarchy, not just the OIC's direct reports.
PASS: entries present with correct names and times.
```

---

### Phase 3 — Local Tracking Engine

**Tasks**
1. `db/`: open the database, WAL mode, migrations (`001_init.sql` = §7), startup integrity check, corrupt-file handling, backup before migrating.
2. `tracker/clock.rs`: a `Clock` trait with `now_wall()` and `now_mono()`; a real version and a fake one for tests.
3. `tracker/engine.rs`: the state machine and tick logic from §6.2 – §6.3. The engine gets an `ActivityProvider`, `Clock` and DB handle. It must have **no Windows code in it**.
4. Tick loop: tokio interval every 2 s. It keeps running while the window is hidden.
5. Event listener: lock/unlock/sleep/wake/shutdown from the hidden window → channel → engine.
6. Crash recovery on startup (close open sessions at `last_seen_at`) and auto-resume when `was_tracking = yes`.
7. Save `last_seen_at` every 30 s.
8. Apply office settings from `app_state` (idle limit, title mode). If `APP_ONLY`, don't save titles at all.
9. Commands: `start_tracking`, `pause_tracking`, `resume_tracking`, `stop_tracking`, `get_tracking_state`, `get_today_summary`, `get_today_timeline`.
10. Rolling log file (`tracing-appender`, daily files, keep 7). Never log window titles.
11. **Rust unit tests with the fake clock and fake provider** for:
    - app switch → two sessions with correct times;
    - flicker (app shown for one tick) → ignored;
    - idle → app session ends at last input and idle session starts there;
    - return from idle;
    - 10-minute chunking (active and idle);
    - pause / resume / stop;
    - time gap > 30 s → session closed at last tick;
    - wall clock jump → `clock_changed` set, durations still correct;
    - crash recovery;
    - totals: tracked = active + idle.
12. A simple debug page in the app listing today's local sessions (id, type, app, start, end, duration, sync status).

**Deliverables**
- Tracking works fully offline and stores correct sessions in SQLite.
- All engine unit tests pass.

**Tests**

```text
Test 3.1 [N] Application switching
1. Start tracking.
2. Use VS Code 2 min. 3. Chrome 2 min. 4. VS Code 2 min.
5. Stop. Open the debug page.
Expected: VS Code ~2m, Chrome ~2m, VS Code ~2m. No overlaps, no gaps between them.
PASS: each within ±5 seconds; end of one = start of next.

Test 3.2 [N] Rapid switching
1. Start tracking. Alt-Tab quickly between 3 apps for 30 seconds (under 1 s each).
2. Then stay in Notepad for 1 min. Stop.
Expected: very short flickers don't create sessions. Sessions add up to the real time.
PASS: sum of durations = time from start to stop (±5 s).

Test 3.3 [N] Idle (use a 1-minute idle limit for testing)
1. Start tracking. Use Chrome for 1 min.
2. Don't touch anything for 3 min.
3. Move the mouse. Use Chrome 1 min. Stop.
Expected: Chrome ~1m → IDLE ~3m (starting when you stopped touching, labeled "Idle (in Google Chrome)") → Chrome ~1m.
PASS: idle starts at your last input (±5 s), not 1 minute later.

Test 3.4 [N] Meeting label
1. Start tracking. Open Zoom (or any video) and don't touch anything for longer than the idle limit.
Expected: IDLE session with idle_app_name = "Zoom". The timeline shows "Idle (in Zoom)".
PASS: label correct.

Test 3.5 [N] Long session chunking
1. Start tracking. Stay in one app, active, for 25 minutes.
Expected: 3 sessions (10m, 10m, ~5m), back to back, same app.
PASS: no gaps; total ~25m.

Test 3.6 [N] Pause
1. Start tracking. 1 min. Pause. Wait 2 min. Resume. 1 min. Stop.
Expected: two sessions of ~1m each with a 2-minute gap. Tracked time today ≈ 2m.
PASS: paused time not counted.

Test 3.7 [N] Lock screen
1. Start tracking. Win+L. Wait 2 min. Unlock.
Expected: session ends at lock. Gap of ~2 min. New session after unlock.
PASS: locked time not counted.

Test 3.8 [N] Sleep / wake
1. Start tracking. Sleep the PC for 5 min. Wake and unlock.
Expected: session ended at sleep. Gap ~5 min. Tracking continues after unlock.
PASS: no session covers the sleep time.

Test 3.9 [F] Crash
1. Start tracking. Use an app for 3 min.
2. Kill the app in Task Manager (End task).
3. Wait 2 min. Start the app again.
Expected: the open session is closed at its last save (≤ 30 s before the kill). Tracking resumes with a "Tracking resumed" notice.
PASS: no session covers the 2 minutes the app was dead; nothing lost except ≤ 30 s.

Test 3.10 [F] Reboot
1. Start tracking. Restart Windows. Log back in.
Expected: the session ended at shutdown. The app starts by itself and tracking resumes (needs launch at startup from Phase 5 – until then, start it by hand).
PASS: no session covers the reboot time.

Test 3.11 [D] Clock change
1. Start tracking. After 2 min, move the Windows clock forward 1 hour. Wait 2 min. Put it back. Stop.
Expected: sessions have correct durations (~2 min each); the one after the change has clock_changed = 1.
PASS: no session is 1 hour long.

Test 3.12 [F] Offline
1. Turn off Wi-Fi. Track for 10 minutes using several apps. Turn Wi-Fi on.
Expected: tracking never stops; all sessions are in SQLite.
PASS: sessions complete, no errors shown to the user.

Test 3.13 [F] Corrupted database
1. Quit the app. Open tracker.db in a text editor and replace some bytes in the middle. Save.
2. Start the app.
Expected: app starts, notice shown, old file renamed to tracker.db.corrupt-..., fresh database, tracking works.
PASS: no crash.

Test 3.14 [D] Totals match
1. After a day of tests, compare the Today screen with the debug list.
Expected: Tracked = Active + Idle; the app list adds up to Active.
PASS: numbers match exactly.

Test 3.15 [P] Resource use while tracking
1. Track for 1 hour with the window hidden.
Expected: CPU average < 1%. Memory < 150 MB total. tracker.db grows < 1 MB.
PASS: within those numbers.
```

---

### Phase 4 — API Sync and Database

**Tasks**
1. `packages/shared`: TS types for the sync request/response (§10.1) — matched against the Laravel Form Request's validation rules by hand.
2. Laravel `AgentSyncRequest` + `AgentController@sync`: `POST /api/v1/agent/sync`, exactly as in §10.1 (DB transaction, duplicates, one-PC rule, deactivated rule).
3. `SummaryService`: add sessions into daily totals, splitting at midnight in the office timezone.
4. Middleware: `throttle:agent-sync` rate limit, `CheckAgentVersion` (`X-Agent-Version` header vs `office_settings.min_agent_version`, `426` if too old).
5. `php artisan tracker:prune` scheduled command (§8 Housekeeping) for retention (sessions 30 d, summaries 90 d, audit 365 d). Register the cron entry that drives Laravel's scheduler, and write the steps in `docs/SETUP.md`.
6. Rust `sync/client.rs` + `sync/worker.rs` (§11.1): batching, retry wait times, 401 handling, commands, settings.
7. Rust `auth.rs`: hold the Sanctum token from Credential Manager; no refresh step needed (§9.2) — just re-send it until a `401` says it's no longer valid.
8. Detect "internet is back" (a successful `/health` call, checked every 30 s while offline) → sync now.
9. `logout` command (§6.3): stop, sync with a 30 s limit, then sign out or show the offline message. Data stays tied to the user id.
10. When a user logs in on a PC that holds unsent data from **another** user: keep it; it is sent when that user logs in again.
11. Laravel feature tests (Pest/PHPUnit):
    - valid batch → accepted;
    - same batch twice → second time all `duplicates`, totals unchanged;
    - bad sessions → rejected with the right reason;
    - midnight split;
    - one-PC takeover;
    - deactivated user rule;
    - `tracker:prune` actually deletes rows past retention and leaves newer ones.

**Deliverables**
- Sessions from the desktop app appear in MySQL within ~2 minutes (and ≤ 10 minutes for long sessions), with correct daily totals.

**Tests**

```text
Test 4.1 [N] Sessions reach the database
1. Track 5 minutes across 2 apps. Wait 3 min.
2. Query the database (`php artisan tinker` → `Session::latest()->take(10)->get()`, or any MySQL client).
Expected: the same sessions (same ids) as the local debug page.
PASS: all present, local rows marked SYNCED.

Test 4.2 [N] Daily summary
1. After 4.1, query `daily_summaries` for that user_id/today.
Expected: tracked_seconds = sum of sessions; apps per app correct.
PASS: matches within 1 s.

Test 4.3 [N] Live status
1. Start tracking. Query `employee_statuses` for that user_id. Go idle past the limit. Pause. Stop.
Expected: state changes ACTIVE → IDLE → PAUSED → NOT_TRACKING, each within ~2 min.
PASS: all states seen.

Test 4.4 [D] Duplicate upload
1. Take a sync request (from the log, or build one with curl) and send it twice.
Expected: the 2nd response lists all ids under "duplicates". Summary totals don't change.
PASS: no double count.

Test 4.5 [F] Offline then online
1. Wi-Fi off. Track for 30 min. Wi-Fi on.
Expected: within ~1 minute of reconnecting, all sessions upload. Totals correct.
PASS: count in the database = count locally; no duplicates.

Test 4.6 [F] API down
1. Point the app at a wrong API URL (dev build), or stop PHP-FPM / nginx on the dev server.
2. Track 10 minutes. Restore the API.
Expected: tracking continues; the app shows "Sync pending"; data uploads once the API is back.
PASS: nothing lost.

Test 4.7 [F] Database failure
1. In dev, temporarily stop MySQL (or revoke the Laravel DB user's privileges).
2. Track 5 minutes. Restore the database.
Expected: API returns 5xx; the app retries later; data arrives after the fix.
PASS: nothing lost, nothing duplicated.

Test 4.8 [F] Token still valid after hours
1. Leave the app tracking for 2+ hours.
Expected: syncing continues without asking to log in (Sanctum tokens don't need refreshing — see §9.2).
PASS: no 401 errors that stop syncing.

Test 4.9 [N] Logout online
1. Track 3 min. Log out right away.
Expected: "Sending your data…" then signed out. All sessions in the database.
PASS: 0 pending sessions.

Test 4.10 [F] Logout offline
1. Wi-Fi off. Track 3 min. Log out.
Expected: "You're offline, your data will be sent next time you log in." Signed out.
2. Wi-Fi on. Log in as the same user.
Expected: the data uploads.
PASS: sessions appear in the database after re-login.

Test 4.11 [N] Two computers
1. Start tracking on PC 1. Then start tracking on PC 2 with the same account.
Expected: within ~2 min PC 1 stops, with the notice "Tracking was started on another computer."
PASS: no overlapping time counted.

Test 4.12 [D] Midnight
1. (Dev) Set the office timezone so midnight is a few minutes away, or set the PC clock near midnight.
2. Track across midnight.
Expected: time is split between two daily summaries.
PASS: yesterday + today = total session time.

Test 4.13 [S] Upload for someone else
1. With employee A's token, send a sync whose body includes "uid": "<B id>".
Expected: the extra field is ignored (not a recognized Form Request field); sessions are saved under A.
PASS: nothing written for B.

Test 4.14 [S] Bad data
1. Send sessions with: endedAt before startedAt; duration 99999; startedAt 60 days ago; a 5,000-character title; 101 sessions.
Expected: first four rejected with reasons; the 101-session request gets 400.
PASS: nothing bad saved.

Test 4.15 [S] Deactivated during work
1. Someone tracks offline for 10 min. Their manager deactivates them. They go online.
Expected: sessions from before deactivation are accepted; the app then signs out.
PASS: correct sessions saved; no newer ones accepted.

Test 4.16 [F] Old app version
1. Set office_settings.min_agent_version higher than the installed version.
Expected: the app shows "Please update the app" and keeps tracking locally.
PASS: no data lost; syncs after the update.

Test 4.17 [P] Big backlog
1. Insert 3,000 fake pending sessions into SQLite (dev script). Go online.
Expected: uploads in batches of 100 with no errors; the UI stays responsive.
PASS: all uploaded within ~15 minutes.

Test 4.18 [P] Rate limit
1. Call /api/v1/agent/sync 50 times in 1 minute with curl.
Expected: after 30, responses are 429.
PASS: limit works.
```

---

### Phase 5 — Employee Desktop Screens

**Tasks**
1. **Home screen:**
   - big status (Active / Idle / Paused / Not tracking / Away);
   - **Start**, **Pause/Resume** and **Stop** buttons;
   - current app and window title;
   - timer for the current work period;
   - today's tracked / active / idle;
   - today's app list;
   - today's timeline (simple colored bars);
   - sync status ("All data sent" / "12 sessions waiting to send").
2. **Settings screen:**
   - "What we track" (the same text as the consent screen);
   - idle limit and title mode (read-only, set office-wide by the OIC);
   - launch at startup (on by default);
   - app version and "Check for updates";
   - "Open log folder";
   - Log out.
3. Tray icon: colors for tracking / idle / paused / off. Menu: Open, Start/Pause/Stop, Quit.
4. Windows notifications for: tracking resumed after restart, stopped because another PC started, please log in again, please update.
5. Launch at startup with `tauri-plugin-autostart`, **on by default**.
6. The UI uses Rust events (§11) instead of polling. It may poll `get_current_activity` once a second only while the window is visible.
7. Hide the window on close; quit only from the tray.

**Deliverables**
- A complete employee app. An employee can use it without help.

**Tests**

```text
Test 5.1 [N] Controls
1. Press Start → Pause → Resume → Stop.
Expected: status and buttons update each time; the tray icon color changes.
PASS: all states correct.

Test 5.2 [N] Live display
1. While tracking, switch apps.
Expected: current app on screen changes within ~2 s.
PASS: correct.

Test 5.3 [N] Today totals
1. Track 10 min with some idle.
Expected: totals and app list on screen match the debug page.
PASS: exact match.

Test 5.4 [N] Timeline
1. After 5.3, look at the timeline.
Expected: bars in the right order and roughly the right size; idle bars labeled "Idle (in X)".
PASS: matches the session list.

Test 5.5 [N] Launch at startup
1. Reboot while tracking.
Expected: the app starts after login and tracking resumes with a notice.
PASS: works without clicking anything.

Test 5.6 [N] Tray
1. Close the window. Use the tray menu to Pause and Open.
Expected: the window hides, tracking continues, and the menu works.
PASS: all actions work.

Test 5.7 [N] Transparency
1. Open Settings → "What we track".
Expected: lists everything tracked and says who can see it and how long it's kept.
PASS: matches §16.

Test 5.8 [F] Offline indicator
1. Wi-Fi off, track 5 min.
Expected: "X sessions waiting to send". Wi-Fi on → "All data sent".
PASS: correct.

Test 5.9 [P] Window open vs hidden
1. Compare CPU with the window open and hidden, 10 min each.
Expected: hidden < 1% average; open < 3%.
PASS: within targets.
```

---

### Phase 6 — Manager Dashboard

**Tasks**
1. Laravel routes/controllers: `GET /api/v1/employees`, `/employees/{id}/summary`, `/employees/{id}/timeline` (§10), all scoped to the caller's `HierarchyService::allDescendantIds()` (§9.1). Audit "viewed timeline".
2. **Overview page (`/`):**
   - cards: tracking now / idle now / not tracking (includes offline) — counted over the caller's visible set only, so an OIC's cards cover the whole office and a Team Leader's cover just their team;
   - today's total tracked / active / idle, same scope;
   - a table (Name, Role, Status, Tracked, Active, Idle, Current app, Last activity) of everyone in the caller's visible set.
   - Refresh every 60 s, only while the browser tab is visible.
3. **Employee page (`/employees/[id]`):** only reachable if `[id]` is in the caller's visible set (self included) —
   - date picker: Today / Yesterday / pick a date;
   - totals: tracked / active / idle;
   - app breakdown: top 5 apps + "Other", with times (`5h 02m`);
   - timeline: a horizontal bar from first to last activity, one colored block per segment, idle in grey with "Idle (in Zoom)" on hover, gaps left empty;
   - a session list under the timeline (time range, app, title, duration), paginated.
4. Build the timeline with plain HTML/CSS (divs with widths as percentages). No chart library.
5. Time format helper: `7h 24m`, `33m`, `45s`.
6. Show all times in the office timezone.
7. **Settings page** (OIC only — §9.1; hidden from the nav for Project Managers and Team Leaders): idle limit (1–30 min), window title mode, office timezone, minimum app version.
8. **Audit page** (OIC only): simple paginated list, covering the whole hierarchy.

**Deliverables**
- A working manager dashboard, deployed to dev, correctly scoped at every tier of the hierarchy.

**Tests**

```text
Test 6.1 [N] Overview, scoped
1. Under one Team Leader, have 2 direct reports: one tracking, one not. Have another
   Team Leader (different team) with their own tracking report.
2. Log in as the first Team Leader.
Expected: cards and table show only their own 2 reports — 1 tracking, 1 not — not the other team.
PASS: correct within ~2 minutes, no cross-team leakage.

Test 6.2 [N] Idle status
1. Someone in the caller's visible set goes idle past the limit.
Expected: status "Idle" within ~2 minutes.
PASS: correct.

Test 6.3 [N] Offline status
1. Someone's PC loses internet while tracking.
Expected: after ~5 min the status shows "Offline (last seen HH:MM)".
PASS: correct.

Test 6.4 [D] Totals match
1. Compare someone's dashboard totals for today (as seen by their manager) with the Today screen in their own desktop app.
Expected: same (±2 min for sync delay).
PASS: match.

Test 6.5 [N] App breakdown
1. Check an employee page.
Expected: app times add up to Active; the top 5 plus Other are shown.
PASS: sums correct.

Test 6.6 [N] Timeline
1. Compare the timeline (as seen by a manager) with that person's own timeline.
Expected: same blocks in the same order; long sessions shown as one block (not 10-min pieces).
PASS: match.

Test 6.7 [N] Dates
1. Switch Today → Yesterday → a date 2 weeks ago → a date 2 months ago.
Expected: correct data. Dates older than 30 days show totals only, with the note "Detailed timeline is kept for 30 days."
PASS: correct.

Test 6.8 [S] Direct URL outside your hierarchy
1. Log in as a Team Leader and go to /employees/<id of someone on a different team>.
Expected: blocked (403 / signed out of that view).
PASS: no data shown.

Test 6.8b [S] Individual contributor, direct URL
1. Log in to the dashboard as an individual-contributor role (login itself already
   rejected per Test 2.3, so this confirms the API route is independently guarded,
   not just the login screen).
2. curl the employees endpoint directly with their token.
Expected: 403.
PASS: no data shown.

Test 6.9 [S] Deactivated manager
1. Deactivate a Project Manager while they have the dashboard open.
Expected: within 60 s their next request fails with 403 and they're signed out.
PASS: access stops.

Test 6.10 [S] Settings/audit hidden from non-OIC managers
1. Log in as a Project Manager or Team Leader.
Expected: no Settings or Audit nav item; direct navigation to those routes 403s.
PASS: only OIC reaches them.

Test 6.11 [P] Page speed
1. As OIC (largest visible set), open the overview with everyone, then someone's busy day.
Expected: each loads in under 2 seconds.
PASS: within target.

Test 6.12 [P] Query count
1. Open the overview once with Laravel's query log/Debugbar (dev only) on.
Expected: a small, constant number of queries regardless of how many people are in the caller's visible set (eager-loaded, not N+1 — see §10.3).
PASS: no N+1 query pattern.
```

---

### Phase 7 — Reliability and Security Pass

Most of these were tested in earlier phases. This phase repeats them **together, on the release build**, and fixes anything found.

**Tasks**
1. Build a release version and install it on 2–3 real employee PCs (a pilot) for 1 week.
2. Run the full edge-case list (§13) on the release build.
3. Check Laravel logs (`storage/logs/laravel.log`) for errors and slow requests (`php artisan pail` or a query-time log during the pilot). Fix the top issues.
4. Check database size and query numbers against §14.
5. Go through the security checklist (§15). Write down anything not done and why.
6. Test a restore: back up MySQL (`mysqldump`) to a separate location, and write down how to restore it (`mysql < backup.sql` into a scratch database, verify row counts).

**Deliverables**
- A pilot report: bugs found and fixed, resource numbers, office server load (CPU/memory/disk on the server itself, not just the client PCs).
- The security checklist completed.

**Tests**

```text
Test 7.1 [D] Full-day accuracy
1. A pilot employee works a normal day. At the end, compare with their own notes (start, lunch, end).
Expected: start/end within 1 minute; lunch shows as a gap or idle.
PASS: matches.

Test 7.2 [F] Everything breaks
1. During one tracked hour: turn Wi-Fi off/on 3 times, kill the app once, sleep the PC once, lock it once.
Expected: sessions only missing for the dead/asleep/locked times; no duplicates; everything synced in the end.
PASS: local count = database count; totals match.

Test 7.3 [S] Security sweep
1. Repeat tests 2.4–2.8, 2.13, 4.13, 4.14, 6.8 and 6.8b against production.
Expected: same results.
PASS: all blocked.

Test 7.4 [D] Duplicates check
1. Run a small script comparing session ids across the server database and the local DBs.
Expected: every local SYNCED id is in the `sessions` table exactly once.
PASS: 0 missing, 0 extra.

Test 7.5 [P] One-week resources
1. On pilot PCs, check CPU, memory and tracker.db size after a week.
Expected: within §14 targets.
PASS: all within targets.

Test 7.6 [F] Restore drill
1. Back up the dev database (`mysqldump`). Drop a test table. Restore it.
Expected: data back.
PASS: restore works and the steps are written down.
```

---

### Phase 8 — Installer and Updates

**Tasks**
1. Tauri NSIS installer, **per-user install** (no admin rights needed). App name, icon, version.
2. Sign the installer and the exe with the code-signing certificate from Phase 1.
3. Uninstaller: removes the app and the autostart entry. Offer "Also delete local data" (unticked by default). Warn if there's unsent data.
4. Updater: `tauri-plugin-updater`. Generate the updater key pair; keep the **private key only in GitHub Secrets**. Host `latest.json` + installers as static files on the office server, served by the same nginx (e.g. `https://<your-domain>/updates/`), **write-only from the release workflow** (uploaded over SSH/`rsync` with a deploy key — not a public upload endpoint). Keep only the last 3 versions on disk.
5. Check for updates at startup and every 6 hours. Install only when **not tracking**, or when the user clicks "Update now" (which stops tracking cleanly first). Resume tracking after restarting if it was on.
6. Database migrations run at startup with a backup first (§7.4 — this is the SQLite backup on the employee's PC; the server's own `php artisan migrate` for the MySQL schema is a separate, manual release step, see `docs/RELEASE.md`). If a local migration fails → restore the backup and show an error.
7. GitHub Actions release workflow: on tag `v*` → build on `windows-latest` → sign → `rsync`/`scp` the installer and updated `latest.json` to the office server's `/updates/` directory over SSH (deploy key stored in GitHub Secrets) → delete versions older than the last 3.
8. Laravel: `office_settings.min_agent_version` (§10.1) forces very old versions to update.
9. `docs/RELEASE.md`: how to release (including running `php artisan migrate` on the office server for any API-side schema changes), and how to roll back (point `latest.json` at the previous version).

**Deliverables**
- A signed installer. Automatic updates work. The release process is written down.

**Tests**

```text
Test 8.1 [N] Clean install
1. On a fresh Windows 10 and a fresh Windows 11 PC, run the installer.
Expected: installs without an admin prompt; no SmartScreen "unknown publisher" warning (if signed); the app starts.
PASS: both OSes work.

Test 8.2 [N] Update
1. Install v1.0.0. Release v1.0.1. Wait, or click "Check for updates".
Expected: update installs; the app restarts; tracking resumes; local data still there.
PASS: version shows 1.0.1; nothing lost.

Test 8.3 [F] Update while tracking
1. While tracking, publish an update.
Expected: it doesn't install until you stop or click "Update now". No session is cut wrongly.
PASS: correct.

Test 8.4 [F] Broken download
1. Publish an update whose signature doesn't match.
Expected: the update is refused; the old version keeps working.
PASS: still running the old version.

Test 8.5 [F] Migration failure
1. (Dev) Ship a migration that fails on purpose.
Expected: the backup is restored; an error is shown; the app still starts on the old schema or asks you to update again.
PASS: no data lost.

Test 8.6 [N] Uninstall
1. Uninstall with "delete local data" unticked, then reinstall.
Expected: the app is removed; autostart is removed; after reinstall, old unsent data is still there.
PASS: correct.

Test 8.7 [N] Rollback
1. Follow docs/RELEASE.md to roll back to the previous version.
Expected: PCs get the previous version (as a new "update").
PASS: works.
```

**At the end of Phase 8 the MVP is complete (see §20).**

---

### Phase 9 — (Optional) Browser Website Tracking

Only after the MVP has been stable for a few weeks.

**Tasks (outline)**
1. A Chrome/Edge extension (Manifest V3) that reads **only the domain** of the active tab (e.g. `github.com`). No page content, no full URLs, no incognito tabs.
2. The extension talks to the desktop app through **Native Messaging** (a small host registered by the installer). It does not talk to the internet.
3. The engine adds an optional `domain` field to APPLICATION sessions for browsers. Domain changes act like app switches.
4. Office setting: `websiteTracking: OFF | DOMAIN_ONLY` (default OFF). Update the consent screen and bump `consentVersion`.
5. Dashboard: a domain breakdown on the employee page.

**Tests (outline):** the domain is recorded correctly; incognito is not recorded; setting OFF records nothing; the consent screen shows up again after enabling; no full URLs are ever stored.

---

### Phase 10 — Removed: Screenshots

Screenshots are **not part of this project** (decision: no paid screenshot storage). Do not build screenshot capture, upload or storage. If this is ever reconsidered, it needs a new decision, new consent text and a storage budget.

---

### Phase 11 — Reports

**Tasks**
1. Laravel `GET /api/v1/reports/daily?from&to&uid?` (manager, scoped to `HierarchyService::allDescendantIds()` same as §10's employee routes), built from `daily_summaries` only (max 92 days, to match retention).
2. Reports:
   - **Daily employee report:** one row per person per day (tracked / active / idle, first / last activity), limited to the caller's visible set;
   - **App usage report:** app totals for a date range, same scope;
   - **Team report:** everyone visible to the caller, totals for a date range.
3. CSV export (the controller returns a `text/csv` response — Laravel's `StreamedResponse` for large ranges). Times as `HH:MM` and also as plain seconds.
4. All days follow the office timezone setting.
5. Dashboard `/reports` page with a date range, employee filter and "Download CSV".

**Tests**

```text
Test 11.1 [D] Totals match
1. Run the team report for yesterday.
Expected: each row matches that employee's page for yesterday.
PASS: exact match.

Test 11.2 [N] CSV
1. Download CSV for last week and open it in Excel / Google Sheets.
Expected: opens cleanly; one row per employee per day; numbers match the dashboard.
PASS: correct.

Test 11.3 [S] Individual contributor access, and hierarchy scope
1. Call /api/v1/reports/daily with an individual-contributor token.
Expected: 403.
2. Call it with a Team Leader's token, requesting `uid` of someone outside their team.
Expected: that person's rows are excluded (or 403 if `uid` was requested directly).
PASS: both blocked.

Test 11.4 [N] Timezone
1. Change the office timezone in dev and re-run a report for a new day.
Expected: day boundaries follow the setting.
PASS: correct.

Test 11.5 [P] Large range
1. Run a 92-day team report.
Expected: finishes in under 5 seconds; reads ≈ employees × days.
PASS: within target.
```

---

## 13. Edge Cases — Expected Behavior

| # | Situation | What should happen |
|---|---|---|
| 1 | Switches apps rapidly | Apps shown for less than ~2 s (one tick) are ignored. Time goes to the app being switched from. Totals still add up. |
| 2 | Becomes idle | App session ends at the **last input time**. An IDLE session starts there, labeled with the front app. |
| 3 | Returns from idle | IDLE session ends at the first new input. A new app session starts. |
| 4 | Stops tracking | Open session closed now; sync triggered; status NOT_TRACKING. |
| 5 | Pauses | Open session closed; no sessions until Resume; status PAUSED. Paused time isn't counted. |
| 6 | Closes the window | Window hides to the tray. Tracking continues. |
| 7 | Quits from the tray | Asks to confirm. Stops tracking, syncs (up to 10 s), exits. |
| 8 | App crashes | On restart: open session closed at `last_seen_at` (≤ 30 s lost). Tracking resumes with a notice. |
| 9 | Windows reboots | Session closed on shutdown. App auto-starts, tracking resumes with a notice. |
| 10 | Computer sleeps | Session closed at sleep. Sleep time not counted. |
| 11 | Computer wakes | Tracking resumes after unlock. If no sleep event arrived, the 30-second gap check closes the old session at the last tick. |
| 12 | Lock screen | Session closed at lock. Status AWAY. Resumes on unlock. |
| 13 | Internet disconnects | Tracking unaffected. Queue grows. The UI says "waiting to send". |
| 14 | Internet reconnects | Detected within ~30 s; the queue sends in batches of 100. |
| 15 | API unavailable | Retries at 1, 2, 5, 10, then every 30 min. Nothing lost. |
| 16 | Database unavailable | The API returns 5xx; the DB transaction saves nothing half-done; the app retries. |
| 17 | Sanctum token invalid/revoked | No refresh step (§9.2) — the app shows "Please log in again" (password changed, account disabled, or token expired per `sanctum.expiration`), data kept. |
| 18 | System clock changed | Durations use the monotonic clock, so they stay correct. The session is flagged `clockChanged`. The server rejects future-dated sessions and records the clock skew in the status doc. |
| 19 | Logs out | Sends data first. If offline: shows the offline message, keeps the data, sends it on next login. |
| 20 | Different person logs in on the same PC | Each person's unsent data stays tied to their user id and only goes up with their own token. |
| 21 | Employee moves to a new PC | Just log in on the new PC. The old PC's unsent data goes up next time that PC is logged in. |
| 22 | Tracks on two PCs | The newest PC takes over. The old one is told to stop within ~2 min. No double counting. |
| 23 | Employee deactivated | Can't log in or get new tokens. Unsent sessions from before deactivation are accepted. Then the app signs out. |
| 24 | Duplicate sync requests | Same UUIDs → reported as `duplicates`, not saved or counted again. |
| 25 | Large sync queue | 100 per request, up to 20 requests per cycle. Oldest first. The UI stays responsive (syncing runs in the background). |
| 26 | Corrupted SQLite | Detected at startup. Old file renamed and kept. A fresh DB is created, the user is told, and the server is told (`dbReset`). Unsent data in the bad file may be lost; this is logged. |
| 27 | Multiple monitors | Only the one focused window counts. |
| 28 | Full-screen apps | Tracked like any other app. |
| 29 | Apps without window titles | Title saved as empty. The app name still comes from the process. |
| 30 | Desktop / File Explorer | Shown as "Desktop" / "File Explorer". |
| 31 | UAC / system dialogs | Shown as "Windows security prompt" (or "Protected app" if the process can't be read). |
| 32 | Store apps | The real app is found behind `ApplicationFrameHost`. |
| 33 | Watching a video or in a meeting with no input | Becomes IDLE after the limit. Shown as "Idle (in Zoom)". |
| 34 | Session over midnight | Split between the two days in the summaries (office timezone). |
| 35 | Very long session | Saved in 10-minute pieces; shown as one block in timelines. |
| 36 | App version too old | The server says 426. The app keeps tracking locally and asks to update. |
| 37 | Remote Desktop / VM | Shows only as the RDP/VM app. Known limitation. |

---

## 14. Performance Targets (MVP)

| Item | Target |
|---|---|
| CPU (window hidden) | < 1% average, no spikes over 5% |
| CPU (window open) | < 3% average |
| Memory | < 150 MB total (Rust ≈ 20–40 MB; WebView2 is most of the rest) |
| Tick cost | < 5 ms per tick |
| `tracker.db` size | < 20 MB normally (synced rows deleted after 7 days) |
| Network | < 2 MB per employee per day |
| API requests | ≈ 1 every 2 min while logged in → **~250 per employee per 8-hour day** |
| DB writes | ≈ 1 status upsert + new session inserts + 1 summary upsert per sync → **~600–900 per employee per day** |
| DB reads | ≈ 1 per new session (existence check) + dashboard queries |
| Dashboard pages | Load in < 2 s |

**Cost note:** unlike Firestore, there's no per-operation cost on a self-hosted MySQL instance — the only cost is the office server itself. At this write volume (a few hundred small writes per employee per day) a modest VM handles dozens of employees comfortably; watch disk I/O and connection count if the office grows much larger, and add an index or a read replica before it becomes a problem, not after. The dashboard only refreshes while its tab is visible, to keep query load down.

**What keeps it light:** no network polling for activity (only one sync every 2 minutes); Windows calls that take microseconds; SQLite writes only when a session closes plus a tiny update every 30 s; dashboard totals read from ready-made summaries, not recomputed from raw sessions.

---

## 15. Security Checklist

- [ ] **Laravel auth:** email/password only, hashed with bcrypt/argon2id. Password-reset emails for new accounts; managers never see passwords.
- [ ] **Token checks:** Sanctum token validated on every request (hashed lookup, not a raw string compare); `expires_at` enforced; revoked immediately on logout or deactivation (`$user->tokens()->delete()`).
- [ ] **Authorization:** every route has a role/hierarchy check; `/employees/{id}` uses the self-or-visible check (§9.1); settings and audit are OIC-only; covered by tests.
- [ ] **Never trust the client:** the user id comes from the authenticated Sanctum token/session, role from the `users` table. Body fields like `uid`/`role` are ignored (not recognized by the Form Request).
- [ ] **Deactivation works immediately:** `EnsureActiveUser` checks `status` on every request (no cache — it's one indexed lookup already alongside the auth check), and deactivation revokes all existing tokens.
- [ ] **Input validation:** a Laravel Form Request on every endpoint that takes a body or query; size limits; max 100 sessions per sync.
- [ ] **Rate limiting:** per user id on all API routes (`throttle` middleware).
- [ ] **Secrets:** `.env` on the office server only (never committed) — `APP_KEY`, DB credentials, mail credentials; updater private key only in GitHub Secrets; the SSH deploy key for the release workflow scoped to just the `/updates/` upload; nothing secret in the desktop app or the dashboard.
- [ ] **Database not exposed:** MySQL bound to `localhost`/private network, no public port; tested (Test 1.5).
- [ ] **HTTPS only:** nginx terminates TLS (Let's Encrypt, auto-renewed), redirects HTTP → HTTPS, HSTS header set; Laravel forces the HTTPS scheme in generated URLs; Rust `reqwest` uses rustls with certificate checks on.
- [ ] **CORS:** dashboard and API are same-origin, so `config/cors.php` stays closed (no origins allowed).
- [ ] **Local token storage:** Sanctum token in Windows Credential Manager, never in files or SQLite.
- [ ] **Local SQLite:** stored in the user's own `%APPDATA%` (other Windows users can't read it); no passwords or tokens in it. Encryption is not in the MVP (the data is the employee's own activity).
- [ ] **Update files:** served read-only from the office server's `/updates/` directory; only the GitHub release workflow can write to it (SSH deploy key, no public upload endpoint).
- [ ] **Audit logs:** manager actions and timeline views recorded; kept 365 days; readable only by the OIC.
- [ ] **Data retention:** scheduled `php artisan tracker:prune` deletes sessions (30 d), summaries (90 d), audit (365 d); tested in Phase 7.
- [ ] **Logs:** no tokens, keys, passwords or window titles in logs.
- [ ] **Code signing:** installer and exe signed; updater signature checked.
- [ ] **Dependencies:** `pnpm audit`, `cargo audit`, and `composer audit` run locally on a regular cadence (no CI to run them automatically — see Phase 1 task 12).
- [ ] **Server hardening:** firewall only exposes 443 (and 22 for admin SSH, key-only, ideally IP-restricted); `APP_DEBUG=false` and `APP_ENV=production` on the live server; OS security updates kept current.
- [ ] **Backups:** scheduled `mysqldump` to a separate location (off the office server) and a restore tested.
- [ ] **Transparency:** consent screen, tray icon always visible while tracking, "What we track" page.

---

## 16. Privacy

**What the employee is told (consent screen + "What we track" page):**

- **What is tracked:** start/stop/pause times; which app is in front and for how long; the window title (unless the office turned titles off); when you're idle (no mouse/keyboard for X minutes) and which app was on screen then.
- **What is NOT tracked:** keystrokes, typed text, mouse movements, webcam, microphone, file contents, screenshots (never), websites (unless turned on later, with new consent).
- **When:** only while tracking is on (the tray icon shows this). Nothing is tracked while paused, not tracking, locked or asleep.
- **Who can see it:** your manager and whoever is above them in the hierarchy — for example a Developer's data is visible to their Team Leader, that Team Leader's Project Manager, and the OIC, but not to other teams (§9.1). You can always see your own data in the app.
- **How long it's kept:** detailed activity 30 days, daily totals 3 months.

**Safeguards**
- No hidden mode. The tray icon is always visible while tracking.
- Window titles can be turned off office-wide (`APP_ONLY`). The OIC should consider this if titles might contain private information (email subjects, document names).
- Everyone can pause their own tracking.
- No productivity scores.
- Managers' views of other people's timelines are logged, and only the OIC can read that log.
- Only the data needed is collected (no IP history, no hardware inventory beyond the computer name).

**Legal note:** employee monitoring laws differ by country and region (for example consent, notice and data-protection rules). **Have the actual rules reviewed for every place where the office and its employees are located** before rolling this out. Don't assume one rule applies everywhere.

---

## 17. MVP Acceptance Criteria

The MVP is complete when **all** of these are true on the release build:

**Desktop app**
- [ ] Installs with a signed installer on Windows 10 and 11; uninstalls cleanly.
- [ ] Login, forgot password, logout (with the online and offline behavior from §0).
- [ ] Consent screen shown before the first tracking.
- [ ] Start / pause / resume / stop work; the tray shows the state.
- [ ] Detects the front app (including Store apps, Desktop, Explorer, protected apps) and window titles.
- [ ] Idle detection with back-dating and "Idle (in X)" labels.
- [ ] Tracking works with the window hidden, offline, after a crash, after sleep/lock, and after a reboot (auto-start + resume).
- [ ] SQLite storage + queue; nothing lost offline; corrupt DB handled.
- [ ] Sync every 2 min; no duplicates; one-PC rule; automatic updates.
- [ ] Meets the §14 performance targets.

**Backend**
- [ ] Laravel verifies Sanctum tokens/sessions and enforces roles **and hierarchy** on every route (§9.1).
- [ ] Sync is idempotent (Test 4.4 passes).
- [ ] Daily summaries are correct, including midnight splits.
- [ ] The database is not reachable from outside the server; scheduled retention pruning is on and tested.
- [ ] Rate limiting and input validation are on.
- [ ] The office server is HTTPS-only with a valid, auto-renewing certificate.

**Dashboard**
- [ ] Manager-only login (OIC / Project Manager / Team Leader); individual-contributor roles are rejected with a clear message.
- [ ] Overview with live status (tracking / idle / not tracking / offline) and today's totals, scoped to the caller's hierarchy.
- [ ] Employee table: Name, Role, Status, Tracked, Active, Idle, Current app, Last activity.
- [ ] Employee page: totals, app breakdown, timeline, session list — reachable only within the caller's hierarchy.
- [ ] Today / Yesterday / pick a date.
- [ ] Add a direct report (role locked to one tier below the caller) and deactivate/reactivate anyone in the caller's hierarchy.
- [ ] Office settings and audit log, both OIC-only.

**Process**
- [ ] All test plans for Phases 0–8 pass.
- [ ] Security checklist (§15) complete.
- [ ] 1-week pilot done with no data-loss bugs open.
- [ ] `docs/SETUP.md` and `docs/RELEASE.md` written.

**Not in the MVP:** website tracking, reports/CSV, macOS/Linux.
**Not in the project at all:** screenshots.

---

## 18. Recommended Development Order

1. **Phase 0** — Windows spike. *Stop here if anything fails.*
2. **Phase 1** — Repo, tooling, office server provisioning, Laravel API `/health`. **Order the code-signing certificate.**
3. **Phase 3** — Tracking engine, offline only. (Can run alongside Phase 2; it doesn't need the server.)
4. **Phase 2** — Login, users, roles, consent.
5. **Phase 4** — Sync to MySQL.
6. **Phase 5** — Employee screens.
7. **Phase 6** — Manager dashboard.
8. **Phase 7** — Pilot week + fixes.
9. **Phase 8** — Installer + updates → **MVP done.**
10. **Phase 11** — Reports (the most useful next step for an office).
11. **Phase 9** — Website tracking, only if really needed, with new consent.

**Rules for whoever implements this:**
- Finish and test one phase before starting the next.
- Keep all Windows-specific code in `platform/win.rs`.
- Put every new API shape in `packages/shared` first.
- If this plan and the code disagree, update this plan in the same commit.
