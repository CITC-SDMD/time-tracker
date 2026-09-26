# Office Time Tracker — Development Plan

> **How to use this document:** Build the project one phase at a time, in order. Each phase lists its tasks, what "done" looks like, and a manual test plan. Do not start a phase until the previous phase's tests pass.

---

## 0. Decisions Already Made

These are fixed. Do not change them without asking the project owner.

| Topic | Decision |
|---|---|
| Who uses it | **Many government offices ("organizations") on one platform** (decision of 2026-09-26, reversing the earlier "one office only"). Each organization designs its own roles, because no two offices share a hierarchy. Only a **platform superadmin** creates an organization (no sign-up page). Older text in this document that says "the office" means an organization. |
| Accounts | A superadmin adds the **Admin** of an organization (§9.4). The Admin creates the organization's roles and accounts; anyone whose role holds the permission may add people (§9.1). |
| Roles | **Custom roles with permission checkboxes** (decision of 2026-09-26): each organization makes its own roles from a fixed list of permissions, and each role reaches only the person, their team or the whole organization (§9.1). Who reports to whom is a free tree with no tier rules. A new organization starts with only its built-in **Admin** role. Superadmins have one role with their own permissions (§9.4). Everyone tracks their own time the same way. |
| Database | **MySQL/MariaDB only** (server, self-hosted on the office server). SQLite only on the employee's computer. |
| Hosting | **Self-hosted on the platform's server**, reachable from the internet under a domain name (WFH employees are not on an office LAN). One installer and one address for every organization: an account belongs to one organization and emails are unique across the platform. HTTPS via a reverse proxy (nginx) with a Let's Encrypt certificate. |
| Platform | **Windows 10 / 11** first. |
| Pause | Employees **can pause** tracking. |
| Editing time | Employees **cannot edit or delete** their time. |
| Meetings / no input | Shown as **"Idle (in Zoom)"** — idle, labeled with the app that was on screen. |
| Logout | App **sends all unsent data first**. If offline: show *"You're offline, your data will be sent next time you log in"* and keep the data safely on the computer. |
| Detailed session data | Kept **permanently** on the office server. |
| Daily summaries | Kept **permanently** on the office server. |
| Tracking on many PCs | **One computer at a time.** Starting on a second PC stops the first. |
| Screenshots | **Yes (decision of 2026-09-25, reversing the earlier "no").** Stored on the platform's storage server. Each organization's admin sets the interval (or turns them off) in its settings; see Phase 10 for consent, access and the storage budget. |
| Website tracking | **Not wanted** (decision of 2026-09-25). Phase 9 is dropped. |
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
│   ├── dashboard/              # Dashboard (Nuxt) — every account, with the pages its permissions allow; superadmins under /platform
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
│       │   └── Console/Commands/      # (none yet; no retention pruning, the server keeps all data)
│       ├── database/
│       │   ├── migrations/
│       │   └── seeders/               # UserSeeder, DemoHierarchySeeder; console commands tracker:make-superadmin, tracker:make-organization
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

The platform serves many organizations from one database (decision of 2026-09-26): every tenant table carries an `organization_id`, and every query of the application is limited to the request's organization by a global scope (§9.5). All times are stored in UTC (`TIMESTAMP` columns); Laravel converts to the organization's timezone for display. Table names are Laravel's default snake_case plurals; models are singular (`User`, `Organization`, `Role`, `Session`).

```sql
CREATE TABLE organizations (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  slug        VARCHAR(255) NOT NULL UNIQUE,
  status      ENUM('active','suspended') NOT NULL DEFAULT 'active',  -- suspended: nobody can sign in, nothing is deleted
  created_at, updated_at TIMESTAMP
);

CREATE TABLE roles (                                    -- made by each organization from the fixed permission list (§9.1)
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id  BIGINT UNSIGNED NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  name             VARCHAR(100) NOT NULL,
  description      VARCHAR(255) NULL,
  scope            ENUM('self','team','organization') NOT NULL DEFAULT 'self',
  permissions      JSON NOT NULL,                       -- list of permission keys, validated against App\Support\Permissions
  is_system        BOOLEAN NOT NULL DEFAULT 0,          -- the built-in admin role: permissions and scope are locked
  created_at, updated_at TIMESTAMP,
  UNIQUE (organization_id, name)
);

CREATE TABLE users (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id      BIGINT UNSIGNED NULL REFERENCES organizations(id),   -- NULL only for platform superadmins
  role_id              BIGINT UNSIGNED NULL REFERENCES roles(id),           -- NULL only for platform superadmins
  is_superadmin        BOOLEAN NOT NULL DEFAULT 0,      -- platform staff (§9.4)
  is_owner             BOOLEAN NOT NULL DEFAULT 0,      -- the main superadmin: always every platform permission
  superadmin_permissions JSON NULL,                     -- a superadmin's own platform permissions
  name                 VARCHAR(255) NOT NULL,
  email                VARCHAR(255) NOT NULL UNIQUE,    -- unique across every organization
  password             VARCHAR(255) NOT NULL,          -- Laravel hashed (bcrypt/argon2id)
  manager_id           BIGINT UNSIGNED NULL REFERENCES users(id),  -- the reporting line: free, same organization, no loops
  status               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  deactivated_at       TIMESTAMP NULL,
  consent_version      INT NULL,
  consent_accepted_at  TIMESTAMP NULL,
  created_by           BIGINT UNSIGNED NULL REFERENCES users(id),
  created_at, updated_at TIMESTAMP,                    -- Laravel timestamps
  INDEX idx_users_manager (manager_id)
);
```

**The reporting line (§9.1):** `manager_id` is a free tree inside one organization. Nothing forces a tier: any person can report to any other person of the organization. What the application checks is that the manager is in the same organization, is active, and that no loop is made (a person is never put under someone below them). What a person may *see* is decided by their role's scope and permissions (§9.1), not by the shape of the tree.

**Tenant columns:** `organization_id` is also on `employee_statuses`, `sessions`, `daily_summaries`, `devices`, `screenshots`, `audit_logs` (NULL for platform actions), `roles` and `organization_settings`. The columns are nullable in the database only because platform rows have no organization; the models fill them from the request's organization and a test fails if a model with the column lacks the scope. Composite indexes such as `(organization_id, day)` keep per-organization reads fast.

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
  -- retention: none. The office server keeps all rows permanently.
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
  -- retention: none. The office server keeps all rows permanently.
);

CREATE TABLE devices (
  id              CHAR(36) PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL REFERENCES users(id),
  computer_name   VARCHAR(255) NULL,
  agent_version   VARCHAR(32) NULL,
  first_seen_at   TIMESTAMP NOT NULL,
  last_seen_at    TIMESTAMP NOT NULL
);

CREATE TABLE organization_settings (                  -- one row per organization (was the single office_settings row)
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id          BIGINT UNSIGNED NOT NULL UNIQUE REFERENCES organizations(id),
  timezone                 VARCHAR(64) NOT NULL,         -- e.g. "Asia/Manila" — defines what "a day" is
  idle_threshold_seconds   INT NOT NULL DEFAULT 300,
  window_title_mode        ENUM('full','app_only') NOT NULL DEFAULT 'full',
  consent_version          INT NOT NULL DEFAULT 1,
  screenshot_interval_minutes TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- Phase 10: 0 = off, else 5, 10, 15 or 30
  screenshot_random        BOOLEAN NOT NULL DEFAULT FALSE          -- one shot at a random moment inside each block
);

CREATE TABLE platform_settings (                        -- single row, id = 1: what only the platform decides
  id                 TINYINT PRIMARY KEY DEFAULT 1,
  min_agent_version  VARCHAR(32) NOT NULL              -- the oldest desktop app that may still sync (HTTP 426 below it)
);

-- Phase 10: one row per screenshot. The picture and its 320 px thumbnail are files on the private
-- `screenshots` disk (S3 in production, a local folder in development), listed in Spatie Media Library's
-- `media` table (model_type/model_id = App\Models\Screenshot / screenshots.id).
CREATE TABLE screenshots (
  id          CHAR(36) PRIMARY KEY,                       -- uuid v7 made by the desktop app (idempotency)
  user_id     BIGINT UNSIGNED NOT NULL REFERENCES users(id) ON DELETE RESTRICT,  -- an account with screenshots is never deleted
  device_id   CHAR(36) NULL,
  taken_at    TIMESTAMP NOT NULL,                         -- UTC
  width       SMALLINT UNSIGNED NOT NULL,
  height      SMALLINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, taken_at)
);

CREATE TABLE audit_logs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id  BIGINT UNSIGNED NULL REFERENCES organizations(id),   -- NULL for platform actions (organization created, superadmin added)
  actor_user_id    BIGINT UNSIGNED NOT NULL REFERENCES users(id),
  action           VARCHAR(64) NOT NULL,
  target_user_id   BIGINT UNSIGNED NULL REFERENCES users(id),
  details          JSON NULL,
  created_at       TIMESTAMP NOT NULL,
  INDEX idx_audit_created (created_at DESC)
  -- retention: none. The office server keeps all rows permanently.
);

-- Sanctum's own migration creates `personal_access_tokens` (tokenable_id/type, token hash, abilities, expires_at).
```

**`app_key`:** process name in lowercase, without `.exe`, with any character other than `a-z 0-9 _` replaced by `_` (e.g. `code`, `chrome`). Safe as a JSON object key.

**Retention:** none. The office server keeps `sessions`, `daily_summaries` and `audit_logs` permanently, so there is no prune command. Only the desktop app's local SQLite purges already-synced rows after 7 days (§7.4).

**Database access:** MySQL listens only on `localhost` (or the private network if the DB is a separate host) — no public port. Only the Laravel app's DB user connects, with a password from `.env` (never committed). The desktop app and dashboard never get direct database credentials.

**Idempotency without Firestore's "document must not exist" precondition:** `sessions.id` is a `PRIMARY KEY`. Laravel checks which of the incoming UUIDs already exist (`whereIn('id', $ids)->pluck('id')`) inside a DB transaction, inserts only the new ones, and treats a duplicate-key error on insert as a second safety net (race between two concurrent syncs). See §10.2.

---

## 9. Authentication and Authorization

### 9.1 Roles, permissions and reach (organizations)

**Each organization designs its own roles** (decision of 2026-09-26). A role is a name, a **scope** (how far it reaches) and a list of **permissions** ticked from a fixed list that the platform defines. Every account holds exactly one role of its organization. A new organization starts with **only the built-in Admin role** (`is_system`: every permission, the whole organization, only its name and description can be changed, at least one active holder at all times, `LAST_ADMIN`). The admin then makes the other roles and adds people.

**Permissions** (`App\Support\Permissions`, mirrored as keys in `packages/shared`; the labels come from `GET /api/v1/permissions`). Everyone can always see their own data (own day, screenshots, profile), so none of these is needed for that.

| Key | Meaning |
|---|---|
| `people.view` | see the people list and who is tracking now |
| `people.create` | add people |
| `people.update` | edit a name, deactivate or reactivate, send a new link, move to another manager, delete an unused account |
| `people.assign_role` | change someone's role |
| `timeline.view` | open a person's day and timeline |
| `screenshots.view` | open a person's screenshots |
| `reports.view` / `reports.export` | reports, CSV downloads |
| `settings.manage` | the organization's settings |
| `audit.view` | the organization's audit log |
| `roles.manage` | make, edit and delete roles |

**Scope** is one per role: `self` (only the person), `team` (the person and everyone below them in the reporting line, at any depth) or `organization` (everyone in the organization). A permission applies to the people inside the scope. Valid pairs, checked when a role is saved: any permission needs scope `team` or `organization`, and `settings.manage`, `audit.view` and `roles.manage` need `organization`.

**Nobody gives more than they have (anti-escalation).** A role can only be made, edited or given by someone who holds every permission in it and whose own scope is at least as wide. Nobody changes their own role, edits the role they hold, or the role of someone who holds more than they do (`ROLE_ESCALATION`, `CANNOT_EDIT_OWN_ROLE`). A role people hold cannot be deleted (`ROLE_IN_USE`).

**The reporting line** is a free tree in one organization: a new person reports to whoever adds them unless another manager in the adder's reach is chosen, and someone whose scope is the whole organization may leave a person with no manager. Moving a person needs `people.update`, a manager in the mover's reach who is active, and no loop (`WOULD_CREATE_LOOP`). Giving a role and moving are separate changes; nobody's manager changes because their role did.

**Visibility check, in code terms:** `AccessService::visibleUserIds(User)` returns the person alone, the person plus `HierarchyService::allDescendantIds` (scope `team`) or every person of the organization (scope `organization`). `canSee(user, targetId, permission)` is true for the person themselves, or when the role holds the permission and the target is in reach. The same reach gates the people list, days and timelines, screenshots, reports, and who a person may deactivate or edit. Everyone can sign in to the dashboard: what they see follows their permissions (the sidebar and each page ask for one), and the API checks every one again.

### 9.2 How login works

- **Desktop app:** the Vue login screen sends email + password to Rust (`invoke("login")`). Rust calls `POST /api/v1/auth/login` on the Laravel API. Laravel checks the password (`Hash::check`) and, if it's correct and the user is `active`, issues a **Sanctum personal access token** (`$user->createToken('agent-<deviceId>', ['agent'])`, expiring after `sanctum.agent_token_days` (7) without use; every use pushes it forward, at most once a day). Rust keeps that **token in Windows Credential Manager** and sends it as `Authorization: Bearer <token>` on every request. The Vue screens never hold the token. The Sanctum token *is* the credential, and Laravel checks the user's live `status` on every request, so a deactivation takes effect immediately without any token-refresh dance.
- **Which organization?** The account carries its `organization_id`; nobody types an office code. The server finds the account by email (unique across every organization) and every later request works inside that account's organization. Superadmins belong to no organization and do not use the desktop app. A **suspended** organization is refused at sign-in (`ORG_SUSPENDED`), on every request, and at sync; nothing is deleted.
- **Dashboard:** Sanctum's **SPA authentication** (session cookie + CSRF, not a bearer token) — this works because the dashboard is served from the same domain as the API (§2). Every account with a password may sign in; a person of an organization lands on their organization's pages, a superadmin on the platform pages.
- **Forgot password:** "Forgot password" button → Laravel's built-in password-reset flow (`Password::sendResetLink`), emailed via the SMTP relay configured in `.env` (see the Email row in §3).
- **The platform owner (one-time, manual):** `php artisan tracker:make-superadmin "Name" email@example.com` creates the main superadmin with every platform permission and a temporary password printed to the console. **Organizations** are then created from the dashboard, or with `php artisan tracker:make-organization "Office name" "Admin name" admin@example.com`. Write these steps in `docs/SETUP.md`.
- **Adding accounts:** a person whose role holds `people.create` enters a name and email, picks a role they may give and (optionally) who the person reports to. Laravel creates the `users` row in the caller's organization with a random unusable password, then sends a set-password email (the same mechanism as "Forgot password"; the link works for 3 days). If mail isn't configured yet, the dashboard shows the link so it can be sent by hand.

### 9.3 What the Laravel API checks on every request

1. `Authorization: Bearer <Sanctum token>` header (agent) or a valid session cookie + CSRF token (dashboard).
2. Sanctum resolves the token/cookie to a `User` via its own `personal_access_tokens` table (hashed lookup) or the session.
3. Token `expires_at` (if set) is in the future; otherwise `401`.
4. `EnsureActiveUser`: `status = 'active'` and the organization is not suspended — checked on every request, not cached (with one exception for unsent data, see §10).
5. `SetOrganizationContext` puts the request **inside the person's organization**; from here on every model of the tenant tables is limited to it, so an id from another organization does not exist (**404**, never 403).
6. `permission:<key>` middleware for the route (any of several when listed); `self-or-visible:<key>` for per-person routes (the person themselves, or in reach with the permission). Inside a route, controllers check the target is in reach (`403 FORBIDDEN`), never the caller themselves for account changes (`CANNOT_MODIFY_SELF`), and the anti-escalation rules of §9.1.
7. **The user id always comes from the authenticated session/token**, never the request body. Any `uid`, `userId`, `role` or `employeeId` field in the body is ignored. `roleId` and `managerId` are accepted only where documented and always validated against the caller's organization and reach.

### 9.4 The platform layer (superadmins)

A **superadmin** is an account with `is_superadmin`, no organization and no organization role. There is one role, "superadmin", and **each account has its own list of platform permissions** (`App\Support\Permissions::SUPERADMIN`). Anything a superadmin has no permission for is hidden from them (`GET /me` lists their permissions; the sidebar and buttons follow them) and refused by the API (`403 PERMISSION_DENIED`).

| Key | Meaning |
|---|---|
| `organizations.view` | list organizations, open a profile, usage numbers |
| `organizations.create` | create an organization |
| `organizations.update` | rename, suspend, reactivate |
| `organizations.admins.manage` | add, invite again, deactivate and reactivate the admins of an organization |
| `organizations.data.view` | **open an office** read-only: people, timelines, screenshots, reports, audit log |
| `organizations.data.manage` | change things inside an office as its admin would (includes looking) |
| `organizations.detection.manage` | switch the virtual machine detection on or off for one person of an organization (§16) |
| `platform.settings` | edit platform settings (the oldest allowed desktop app version) |
| `platform.staff.manage` | add superadmins, edit their permissions, deactivate them |
| `platform.audit.view` | the platform audit log |

- The **owner** (`is_owner`) always holds every permission and cannot be edited or deactivated. Anti-escalation applies: a superadmin only gives permissions they hold, and cannot change their own or the owner's.
- **Creating an organization** makes its settings and its Admin role only. The organization's profile page has an **Admins** section: a superadmin with `organizations.admins.manage` adds the people who hold the Admin role (name and email, the set-password email is sent), can add several, deactivate and reactivate them, and invite again. The last active admin cannot be deactivated unless the organization is suspended.
- **Opening an office:** `/api/v1/platform/organizations/{id}/office/...` are the organization's own routes, run inside that organization. There the superadmin acts as a virtual organization-wide role: every organization permission with `organizations.data.manage`, the read set (people, timelines, screenshots, reports, audit) with `organizations.data.view`, and nothing without either. The dashboard reuses its organization pages under `/platform/organizations/{id}/office` with a banner.
- **Logging:** a superadmin **looking** at an office's data is **not logged** (owner decision of 2026-09-26): `timeline.viewed`, `screenshots.viewed` and `report.exported` are skipped for superadmins. **Changes** a superadmin makes inside an office are audited like any change, in that organization, under their name. Platform-only actions (`organization.created`, `organization.renamed`, `organization.suspended`, `organization.reactivated`, `superadmin.created`, `superadmin.permissions_changed`, `platform.settings_updated`) carry no organization and are read with `platform.audit.view` (which lists everything superadmins did).

### 9.5 Keeping organizations apart

One database, an `organization_id` on every tenant row, and several layers so a forgotten filter cannot leak: the `BelongsToOrganization` global scope and auto-fill on every tenant model (a test fails if a model with the column lacks it); the request's organization is set right after sign-in and cleared at the start and end of every request; route ids resolve through the scoped query (404 for another organization); `HierarchyService` reads only the organization's people; screenshot files live under `org_{organization_id}/...` on the storage disk; a systematic test (`TenantIsolationTest`) calls every route with a person of organization B against organization A's ids. Emails are unique across the platform. Pre-sign-in lookups (login, password reset) are the only places that read without the scope, and they do so explicitly.

---

## 10. API Design (Laravel)

Base URL: `https://<your-domain>/api/v1`. All responses are JSON. Errors look like `{ "error": { "code": "FORBIDDEN", "message": "..." } }` (a custom exception renderer in `app/Exceptions/Handler.php` maps Laravel's default error shapes to this format).

| Method & path | Who | Purpose | Laravel piece |
|---|---|---|---|
| `GET /health` | anyone | `{ ok: true, version }` | plain route, no controller |
| `POST /api/v1/auth/login` | anyone | Email + password → Sanctum token (agent) | `AuthController@login` |
| `GET /api/v1/me` | logged in | Own profile, `role {id,name}`, `permissions`, `scope`, `organization {id,name,timezone}`, `settings`, `isSuperadmin`, `platformPermissions`, whether consent is needed | `MeController@show` |
| `POST /api/v1/me/consent` | logged in | Record consent `{ consentVersion }` | `MeController@acceptConsent` |
| `PATCH /api/v1/me` | logged in | Change own `name` (email, role and manager are not theirs to change here) | `MeController@update` |
| `PUT /api/v1/me/email` | logged in | Change own email `{ email, currentPassword }`; wrong password 422 `WRONG_PASSWORD`, same address 422 `SAME_EMAIL`, an address anyone else uses (any case) 409 `EMAIL_TAKEN`. The old address gets a notice (no link), reset and invite links for it stop working, the change is audited as `profile.email_changed`, and tokens are kept (the desktop app stays signed in); throttled 5/min | `MeController@changeEmail` |
| `GET /api/v1/me/devices`, `DELETE /api/v1/me/devices/{deviceId}` | logged in | The PCs the person is signed in to the desktop app on (one token each, named `agent-<device id>`), and signing one out (deletes its token; audited `device.signed_out`); throttled 20/min | `DeviceController` |
| `GET /api/v1/admin/employees/{id}/devices`, `DELETE /api/v1/admin/employees/{id}/devices/{deviceId}` | `people.update` + reach | The same for someone the caller manages | `DeviceController` |
| `GET/POST/DELETE /api/v1/me/two-factor`, `POST .../confirm`, `POST .../recovery-codes` | logged in (dashboard cookie only) | Optional two-factor sign-in: state, start (needs the password; returns the secret and an `otpauth://` address), confirm with the first code (returns 8 recovery codes once), new recovery codes and turn off (password and a code); throttled 5-10/min; five wrong codes in 15 minutes lock the checks for that person | `TwoFactorController` |
| `POST /auth/two-factor` | public (a challenge from the password step) | Second step of the dashboard sign-in `{ challenge, code }`; `POST /auth/login` answers `{ twoFactorRequired, challenge }` for a person who has it on | `DashboardAuthController@twoFactor` |
| `DELETE /api/v1/admin/employees/{id}/two-factor`, `DELETE /api/v1/platform/superadmins/{id}/two-factor` | `people.update` + reach; `platform.staff.manage` | Reset for someone who lost their phone (the owner only on the server: `tracker:reset-two-factor`); audited `two_factor.reset` | `TwoFactorController@reset`, `SuperadminController@resetTwoFactor` |
| `PUT /api/v1/me/password` | logged in | Change own password `{ currentPassword, password, password_confirmation }`; wrong current or unchanged password = 422; revokes every token (desktop asks to log in again); throttled 5/min | `MeController@changePassword` |
| `POST /api/v1/agent/sync` | logged in (agent) | Sends status + up to 100 closed sessions. Gets back results + commands. | `AgentController@sync` |
| `GET /api/v1/employees` | logged in | The people in the caller's reach (§9.1) with `role` (its name), `roleId`, live status and today's totals; without `people.view` only the caller's own row | `EmployeeController@index` |
| `GET /api/v1/employees/{id}/summary?from=YYYY-MM-DD&to=YYYY-MM-DD` | self, or `timeline.view` and in reach | Daily totals per day (max 31 days). Another organization's id is 404 | `EmployeeController@summary` |
| `GET /api/v1/employees/{id}/timeline?day=YYYY-MM-DD&cursor=` | self, or `timeline.view` and in reach | Merged timeline segments for one day (max 500 per page) | `EmployeeController@timeline` |
| `POST /api/v1/admin/employees` | `people.create` | Add a person `{ name, email, roleId, managerId? }`: the role must be one the caller may give (`ROLE_ESCALATION`, `ROLE_NOT_FOUND`); the manager defaults to the caller, must be in reach and active (`null` only for someone reaching the organization: else `MANAGER_REQUIRED`); an email used anywhere on the platform is `409 EMAIL_TAKEN` | `AdminEmployeeController@store` |
| `POST /api/v1/admin/employees/import` | `people.create` | Add up to 200 people at once `{ rows: [{ name, email, roleId, managerEmail? }] }` (the dashboard reads a CSV with the columns `name,email,role,manager_email`). Every row gets the same checks as adding one person; if any row is wrong nothing is created and `422 IMPORT_INVALID` lists each problem by row number. A blank manager means the caller; a manager may also be the email of another person in the same file, wherever that row is (a circle is refused). The accounts are made in one database transaction (all or nothing) and the set-password emails are queued and sent after it commits; answer `{ created, invitesQueued }`. A queued email that finally fails marks the person (`users.invite_failed_at`, shown as "Email not delivered" with Resend link, audited as `employee.invite_failed`). Each person is audited (`employee.created`). Limited to 10 requests a minute. |
| `PATCH /api/v1/admin/employees/{id}` | `people.update` for `name`/`status`/`managerId`, `people.assign_role` for `roleId`; the person in reach | Change `name`, `status` (deactivate / reactivate; the last active admin is `LAST_ADMIN`), `roleId` (nobody gives more than they have, nor changes the role of someone who holds more; audited as `employee.role_changed`, with the managers when both change) or `managerId` (**move**: in reach, active, no loop `WOULD_CREATE_LOOP`; `null` only for someone reaching the organization; audited as `employee.moved`). Nothing is applied when anything is refused | `AdminEmployeeController@update` |
| `POST /api/v1/admin/employees/{id}/resend-invite` | `people.update`, person in reach | Email a fresh 3-day set-password link to an active account (returns the link instead when the mail cannot be sent). Audited as `employee.invite_resent`; throttled 10/min | `AdminEmployeeController@resendInvite` |
| `GET /api/v1/reports/daily`, `/reports/apps`, `/reports/team` `?from=&to=&uid=&format=json|csv` | `reports.view` (`reports.export` for CSV) | Phase 11 reports from `daily_summaries`, limited to the caller's reach: one row per person per day / active time per app / totals per person. Max 92 days; `uid` outside the reach = 403, in another organization = 404; CSV has `HH:MM` and seconds, a UTF-8 BOM, and neutralises cells starting with `= + - @`; a CSV download is audited as `report.exported` (not for superadmins) | `ReportController` |
| `POST /api/v1/agent/screenshots` | logged in (agent) | Phase 10. Multipart `id` (uuid), `takenAt`, `deviceId`, `width`, `height`, `image` (one JPEG, at most 1.5 MB and 3840 px, checked by content). `201 stored`; `200 duplicate` for a repeated id; `409 SCREENSHOTS_DISABLED` when the organization has them off (the app then drops its copy); `409 CONFLICT` for another person's id; `422` for a bad file or time; `503 STORAGE_UNAVAILABLE` when the storage server cannot be reached (nothing is kept; the app retries). Files are stored under `org_{organization_id}/{user}/...`. `throttle:60,1` | `ScreenshotController@store` |
| `GET /api/v1/employees/{id}/screenshots?day=YYYY-MM-DD` | self, or `screenshots.view` and in reach | The organization-timezone day's screenshots, oldest first: `[{ id, takenAt, width, height }]`. Looking at someone else's day is audited (`screenshots.viewed`, once per viewer, person and day within 30 minutes; not for superadmins) | `ScreenshotController@index` |
| `GET /api/v1/screenshots/{id}/thumb` and `/image` | self, or `screenshots.view` and in reach | The JPEG, streamed from the storage disk after the reach check (`Cache-Control: private, max-age=3600`). `401` signed out, `403` outside the reach, `404` unknown or another organization. If the thumbnail is not made yet, the full picture is sent. No path, bucket or link ever appears in an answer | `ScreenshotController@show` |
| `GET /api/v1/admin/settings` / `PUT` | `settings.manage` | Read / change the organization's settings (timezone, idle limit, window titles, consent version), including `screenshotIntervalMinutes` (0, 5, 10, 15, 30), `screenshotRandom` and, read-only, `screenshotStorageBytes`. Turning screenshots on (from 0) without raising `consentVersion` in the same request is `422 SCREENSHOTS_NEED_CONSENT` | `AdminSettingsController` |
| `GET /api/v1/admin/audit?cursor=&action=&q=&from=&to=` | `audit.view` | The organization's audit log, newest first, 50 per page; optional filters: exact `action`, `q` (name of who did it or who it was done to), `from`/`to` (organization-timezone days) | `AdminAuditController@index` |
| `GET /api/v1/permissions` | logged in | The fixed permission list with labels and the three scopes | `PermissionCatalogController@organization` |
| `GET/POST /api/v1/roles`, `PATCH/DELETE /api/v1/roles/{id}` | `roles.manage` (the list also for `people.create` / `people.assign_role`) | The organization's roles `{ id, name, description, scope, permissions, isSystem, memberCount, assignable }`. Rules of §9.1: valid scope/permission pairs (`INVALID_ROLE`), `ROLE_ESCALATION`, `ROLE_NAME_TAKEN`, `CANNOT_EDIT_OWN_ROLE`, `ROLE_LOCKED` (the admin role), `ROLE_IN_USE`. Audited as `role.created` / `role.updated` / `role.deleted` | `RoleController` |
| `GET/POST /api/v1/platform/organizations`, `GET/PATCH /platform/organizations/{id}` | superadmin: `organizations.view` / `.create` / `.update` | Organizations with numbers only (people, admins, storage bytes); create (settings + Admin role only), rename, suspend / reactivate | `Platform\OrganizationController` |
| `GET/POST /api/v1/platform/organizations/{id}/admins`, `PATCH .../{userId}`, `POST .../{userId}/resend-invite` | `organizations.admins.manage` | The Admin role's holders of one organization: add (invite email), deactivate (`LAST_ADMIN`), reactivate, invite again | `Platform\OrganizationAdminController` |
| `/api/v1/platform/organizations/{id}/office/...` | `organizations.data.view` (writes need `.manage`) | The organization's own routes above (employees, reports, screenshots, roles, settings, audit, admin/employees), run inside it | the same controllers |
| `GET/POST/PATCH /api/v1/platform/superadmins`, `GET /platform/permissions` | `platform.staff.manage` | Superadmins with their own permissions (anti-escalation, the owner is untouchable) | `Platform\SuperadminController` |
| `PATCH /api/v1/platform/organizations/{id}/people/{userId}/detection` `{ enabled }` | `organizations.detection.manage` | Switch the virtual machine detection on or off for one person of that organization (default on). Off clears what was stored for them; audited as `detection.toggled` in the organization's audit log | `Platform\PersonDetectionController` |
| `GET/PUT /api/v1/platform/settings` | `platform.settings` | The oldest allowed desktop app version (older apps get 426) | `Platform\PlatformSettingsController` |
| `GET /api/v1/platform/audit` | `platform.audit.view` | Everything superadmins did | `Platform\PlatformAuditController` |

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
1. Middleware already checked the auth token, rate limit, and agent version (`426` if below `platform_settings.min_agent_version`).
2. `AgentSyncRequest` validates the body; invalid → `422`/`400` before the controller runs.
3. If `office_settings.window_title_mode = APP_ONLY`, set every `windowTitle` to `null`.
4. `DB::transaction()` (wraps everything below; MySQL's row locks stand in for Firestore's transaction):
   1. `Session::whereIn('id', $ids)->lockForUpdate()->pluck('id')` — sessions that already exist → `duplicates`. New ones → `accepted`.
   2. **One tracking PC rule:** load `employee_statuses` for this user with `lockForUpdate()`. If `state` is ACTIVE/IDLE and `device_id` is a different PC that synced in the last 5 minutes → the PC whose `trackingStartedAt` is later wins (`device_id` = that one, `tracking_device_since` = its `trackingStartedAt`). If the sending PC started earlier, it is the one told to stop. The old PC gets `commands.stopTracking = true, stopReason: "STARTED_ON_OTHER_PC"` on its next sync, and its sessions starting after `tracking_device_since` are rejected (`OTHER_DEVICE_ACTIVE`).
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
- If a full batch of 100 succeeded, send the next batch straight away, paced about 2.2 seconds apart to stay under the 30-per-minute rate limit, until nothing is waiting (with a safety cap of 300 batches per pass). This clears a big backlog after being offline without a pause.
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

> **Superseded in part (2026-09-26):** this phase was built as written and then generalised to many organizations with custom roles and permissions (Phase 12, §9). The OIC / Project Manager / Team Leader tiers, `tracker:make-oic` and the "one tier below" rule described here are history; read §9 for the rules that apply now.

**Tasks**
1. `composer require laravel/sanctum`, publish its config/migration, run `php artisan migrate`.
2. `config/sanctum.php`: set `expiration` (e.g. 30 days) for agent tokens; `SANCTUM_STATEFUL_DOMAINS` for the dashboard's cookie-based SPA auth (§9.2).
3. Migration: add `role`, `manager_id`, `status`, `deactivated_at`, `consent_version`, `consent_accepted_at`, `created_by` to `users` (§8).
4. `app/Services/HierarchyService.php`: loads `id, manager_id` for all users once per request and exposes `allDescendantIds(User $of): array` and `isManagerRole(User $user): bool` (§9.1). Used everywhere a visibility or "can I manage this account" check is needed, so the list endpoint and the per-record 403 checks can never disagree.
5. Middleware `EnsureActiveUser`: after Sanctum resolves the user, require `status = 'ACTIVE'`. `401` if unauthenticated, `403 ACCOUNT_DEACTIVATED` if deactivated.
6. Middleware `EnsureManager` (any manager role), `EnsureOic` (settings, audit), and `EnsureSelfOrVisible($paramId)` (self, or `$paramId` in `HierarchyService::allDescendantIds()`).
7. Routes: `POST /api/v1/auth/login`, `GET /api/v1/me`, `POST /api/v1/me/consent`, `POST/PATCH /api/v1/admin/employees`, `GET/PUT /api/v1/admin/settings`, `GET /api/v1/admin/audit`.
8. Create account (`AdminEmployeeController@store`): validate the requested `role` is exactly one tier below the caller's own (§9.1) — otherwise `422`; create the `users` row (`manager_id` = caller) → audit log entry. If the email already exists → `409`. **Built as:** a random unusable password plus a welcome email with a set-password link (valid 3 days, kept in its own `password_invite_tokens` table). If the mail cannot be sent, the account is still created and the dashboard shows the link to pass on.
9. Deactivate: set `status = DEACTIVATED`, `deactivated_at`, and revoke all their non-agent tokens (`$user->tokens()->where('name', 'not like', 'agent-%')->delete()`). Agent tokens are kept on purpose: the desktop app needs one to send the data recorded before deactivation (Test 4.15); every other route still returns 403 `ACCOUNT_DEACTIVATED`, and the sync route rejects sessions started after `deactivated_at` and tells the app to sign out. Reactivate does the reverse (no need to reissue a token). Both gated by `EnsureSelfOrVisible` — actually here it's *not* self, just visible — a manager can deactivate anyone in their descendant set, at any depth, not only direct reports.
10. A manager cannot deactivate themselves, and the system refuses to leave zero `ACTIVE` `OIC` rows (`400`) — the OIC-equivalent of "last admin protection."
11. `php artisan tracker:make-oic "Name" email@office.com` — the one-time console command that creates the first account directly as OIC (§9.2), since there's no dashboard yet to do it from. Every other account descends from this one through the normal create-a-direct-report flow.
12. Dashboard: login page (posts to `/login`, Sanctum SPA cookie auth — no client SDK needed). After login call `/api/v1/me`. If the role isn't a manager role → sign out and show "This dashboard is for managers only." Route guard (Nuxt middleware) on every page.
13. Dashboard: `employees/manage` page — list of the caller's visible accounts, add a direct report (role dropdown limited to the one tier below the caller), deactivate / reactivate.
14. Desktop app: login screen → `invoke("login")` → Rust calls `POST /api/v1/auth/login` → Sanctum token saved in Credential Manager → `/api/v1/me`.
15. Desktop app: **consent screen** on first login (and whenever `consent_version` goes up). It lists exactly what is tracked (§16). Tracking can't start until it's accepted.
16. Desktop app: "Forgot password?" link (opens the dashboard's `/forgot-password` page in the system browser, since the desktop app has no mail-sending of its own). **Built:** `POST /auth/forgot-password` and `/auth/reset-password`; a reset link lasts 1 hour, works for any role, and a reset signs out every token of that person.
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
4. Middleware: `throttle:agent-sync` rate limit, `CheckAgentVersion` (`X-Agent-Version` header vs `platform_settings.min_agent_version`, `426` if too old).
5. (Removed: no server-side retention pruning. The office server keeps all data permanently.)
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
    - deactivated user rule.

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
1. Set platform_settings.min_agent_version higher than the installed version.
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

**Phase 4 test results** (2026-09, against real MySQL and `php artisan serve`; 62 Laravel and 43 Rust tests pass)

| Test | Result | How |
|---|---|---|
| 4.1, 4.2 | PASS | Real app. Sessions and daily totals matched. |
| 4.3 | PASS | Server-side status transitions plus Rust worker test of status reports. |
| 4.4 | PASS | Duplicates listed, totals unchanged. |
| 4.5 | Open | Needs Wi-Fi off on a real PC. |
| 4.6, 4.7 | PASS | API stopped, then MySQL stopped. Nothing lost or duplicated. |
| 4.8 | Partly | Token ages checked live: accepted at 2 h, 1 d, 29 d; 401 at 31 d. The real 2-hour soak is open. |
| 4.9, 4.10 | Partly | Rust tests cover the logout flush online and offline; 4.10 passed on the real app. Clicking Log out online is open. |
| 4.11 | Partly | Server side (two devices) and Rust stop-order test pass. The on-screen notice on a second PC is open. |
| 4.12–4.14 | PASS | Laravel tests. |
| 4.15, 4.16 | PASS | Real app. |
| 4.17 | PASS | Real app: 3,000 rows in about 4 min, then 5,000 rows in about 2 min 36 s; totals matched. |
| 4.18 | PASS | 429 after 30 requests. |

Known behaviour: at the end of an idle period the on-screen totals settle to the saved values, because saved idle time is back-dated to the last input. Tracked time does not change.

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

**Phase 5 test results** (2026-09, dev build; 54 Rust tests pass, agent typecheck and lint clean)

| Test | Result | How |
|---|---|---|
| 5.1 | PASS | Start, Pause, Resume, Stop: status, buttons and tray colour update each time. |
| 5.2 | PASS | Current app changes within about 2 s of switching (engine ticks every 2 s). |
| 5.3, 5.4 | PASS | Totals, app list and timeline match the sessions list; idle blocks grey and labelled "Idle (in X)". |
| 5.5 | Open | Needs a reboot while tracking, on an installed build (Launch at startup is only turned on by default in installed builds). |
| 5.6 | PASS | Window hides to tray, tracking continues, tray menu items follow the state. |
| 5.7 | PASS | Settings shows office idle limit and title mode; "What we track" matches §16. |
| 5.8 | Open | Needs Wi-Fi off on a real PC. |
| 5.9 | Open | Needs a release build and CPU measurements. |
| Tray Quit | PASS | Asks "Stop tracking and quit?", Cancel keeps running, "Stop and quit" stops, sends, exits. |

Built differently from the task list:
- "Check for updates" is shown but disabled until the Phase 8 updater exists.
- The counter Reset button is a testing aid and appears in dev builds only.
- The live idle counter starts at 00:00:01 when idle is noticed; when the idle stretch ends the totals settle to the saved (back-dated) values.

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

**As built (OIC pass)** — every page is under the `user` layout at `/user/...`; the sidebar is role-aware (`navFor` in `utils/routes.ts`) and the API refuses what the sidebar hides:

| Page | Who | What it does |
|---|---|---|
| `/user` | every manager | Overview cards + table, scoped, refreshes every 60 s while the tab is visible |
| `/user/employees/[id]` | self or visible | Day view: date control, totals, app breakdown, timeline, session list |
| `/user/people` | every manager | Table with a Manager column, search and filters; add a person, **move** to another manager, resend the set-password link, deactivate / reactivate, delete an unused account |
| `/user/reports` | every manager | Phase 11 reports (daily per person, app usage, team totals) with CSV |
| `/user/settings` | OIC | All five office settings, with validation |
| `/user/audit` | OIC | Filterable audit log with readable action labels |
| `/user/profile` | everyone | Own name and password |

**Browser test results (Playwright + Chromium, 2026-09-25)** — `apps/e2e`, run with `pnpm e2e`. It starts its own API (port 8001) and the built dashboard (port 3101) against a separate `tracker_e2e` database, so `tracker_dev` is never touched, and mail goes to the log, never to Gmail. Every test also fails on any console error or warning, uncaught exception or unexpected 4xx/5xx.

| Area | Covered |
|---|---|
| Sign in, forgot and reset | validation, wrong password, throttle, managers-only rule, sign out, single-use and invalid links |
| Overview and a person's day | every live state, totals, 60 s refresh only while visible, PM / TL / OIC scoping, outside-hierarchy refusal |
| People (CRUD) | create with emailed link and first sign-in, read with search and filters, update (resend link, move manager, deactivate / reactivate, sign-out of a deactivated manager), delete and the refusal for accounts with history |
| Profile | name edit, password change and its rules, audit entry |
| Reports | three tabs, 92-day limit, person filter, CSV (byte-order mark, header, rows match, no formulas) |
| Settings and audit | per-field validation, save / discard / persist, OIC-only access, paging, filters, labels |
| Look and feel | light and dark mode, no flash, phone layout, automated accessibility scan of every page in both themes, keyboard use |
| Security | signed-out access, role limits in the UI and the API, CSRF, cross-origin write, script in a name |

Result: **253 of 253 passed, three full runs in a row** (12.3, 12.0 and 11.0 minutes), so no flaky tests. It found six real bugs, all fixed:

1. `minAgentVersion` accepted any text ("banana"), which would lock every desktop app out. It now has to look like `1.2.3` (API test added).
2. The consent version could be lowered through the API although the form says it never goes down. The API now refuses it (API test added).
3. A signed-out request that did not ask for JSON got a 500 instead of 401 (API test added).
4. No page had a title or a language. Pages now read "People · Time Tracker" and `lang="en"` is set.
5. The report tabs were not built as tabs for screen readers (`Tabs.vue`).
6. After closing a dialog with the keyboard, focus fell to the top of the page instead of returning to the button that opened it (`Modal.vue`).

Known and accepted: a reactivated person's old session works again (everything is blocked while they are deactivated); the "email could not be sent" screen is tested with a stubbed reply because the test environment cannot make mail fail. Still to do by hand with the desktop app (Tests 6.3, 6.4, 6.9 and the settings-reach-the-desktop check): they need a real desktop app.

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
Expected: correct data for every date, including full timelines for old dates.
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

**Status (2026-09-26):** the code side is done and written in `docs/SECURITY_REVIEW.md` (the §15 pass, dependency audits, five fixes, automatic tests for the security and query-count checks, the restore drill script). What needs a real server and real people is in `docs/PILOT_CHECKLIST.md` with the report template; the pilot itself has not run.

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
3. Uninstaller: removes the app and the autostart entry. Offer "Also delete local data" (unticked by default). Warn if there's unsent data. **The uninstaller always removes the log folder** (`%LOCALAPPDATA%\com.office.timetracker\logs`, diagnostic text only, no tracking data) through an NSIS installer hook (`bundle.windows.nsis.installerHooks`, post-uninstall step removing only that `logs` folder). It must not use the built-in "delete app data" option for this, which would also remove the local database and any sessions or screenshots not yet sent. Test on the built installer: install, run, uninstall, and check that `logs` is gone while the database is untouched unless "Also delete local data" was ticked. (The app's "Open log folder" and "Today's sessions" links are shown in dev builds only.)
4. Updater: `tauri-plugin-updater`. Generate the updater key pair; keep the **private key only in GitHub Secrets**. Host `latest.json` + installers as static files on the office server, served by the same nginx (e.g. `https://<your-domain>/updates/`), **write-only from the release workflow** (uploaded over SSH/`rsync` with a deploy key — not a public upload endpoint). Keep only the last 3 versions on disk.
5. Check for updates at startup and every 6 hours. Install only when **not tracking**, or when the user clicks "Update now" (which stops tracking cleanly first). Resume tracking after restarting if it was on.
6. Database migrations run at startup with a backup first (§7.4 — this is the SQLite backup on the employee's PC; the server's own `php artisan migrate` for the MySQL schema is a separate, manual release step, see `docs/RELEASE.md`). If a local migration fails → restore the backup and show an error.
7. GitHub Actions release workflow: on tag `v*` → build on `windows-latest` → sign → `rsync`/`scp` the installer and updated `latest.json` to the office server's `/updates/` directory over SSH (deploy key stored in GitHub Secrets) → delete versions older than the last 3.
8. Laravel: `platform_settings.min_agent_version` (§10.1) forces very old versions to update.
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

### Phase 9 — Dropped: Browser Website Tracking

The owner decided website tracking is **not needed** (2026-09-25). Nothing is built for it: no browser extension, no domain field, no office setting. If it is ever wanted again it needs a new decision and new consent text.

---

### Phase 10 — Screenshots

**Decision (2026-09-25):** screenshots are wanted. They are kept on the office server, so the earlier objection (paid screenshot storage) no longer applies. This phase supplies what the earlier decision demanded: consent text, a storage budget and rules for who sees what.

**Rules**
- The OIC sets the interval in office settings: **Off** (default), 5, 10, 15 or 30 minutes, optionally at a random moment inside each block. Desktop apps pick the setting up with the next sync.
- A screenshot is taken **only while tracking is on, including idle time** (a meeting or a quiet spell still shows the screen). Never while paused, stopped, locked or asleep, and never before the person has accepted the current consent.
- Only the **main screen**, as a JPEG about 1280 px wide (plus a 320 px thumbnail). No keystrokes, no webcam, no microphone, no scoring.
- Turning screenshots on requires raising the consent version in the same change (the API refuses otherwise), so everyone accepts the new notice before the first shot.
- **Who sees them:** the person (read-only) and everyone above them in the hierarchy, the same rule as timelines. Employees cannot delete them. Every gallery view is written to the audit log (once per person, day and viewer).
- **Kept permanently** on the office server, like all other data. Files live in the server's private storage (`storage/app/private/screenshots/`), never in a public folder, and are only sent through the API after the hierarchy check.
- **Storage budget:** about 150 KB per shot. At a 10 minute interval that is about 48 shots, 7 to 8 MB per person per day, about 2 GB per person per year (about 4 GB at 5 minutes). For 20 people, plan for 40 to 80 GB a year on a dedicated disk, and include the screenshots folder in backups. The Settings page shows the space used.

**Tasks**
0. **Spike:** prove a screen capture on Windows (capture time under 300 ms, CPU under 2%) before anything else in the agent.
1. API: `screenshots` table, two `office_settings` columns, `POST /agent/screenshots`, list / thumb / image endpoints, audit, tests (§8, §10).
2. Dashboard: settings section, screenshot gallery and viewer on the person page.
3. Agent: capture loop, local queue, upload after sync, consent and "what we track" text, "My screenshots" page.
4. Playwright tests, live tests with a real PC, results written here.

**As built**
- **Server:** Spatie Media Library on the private `screenshots` disk (`SCREENSHOT_DISK=local` while developing, `s3` in production, see `docs/VPS_S3_STORAGE_PLAN.pdf` and `docs/SETUP.md`); `screenshots` table and two `office_settings` columns; upload, list and picture endpoints (§10); the thumbnail is a media conversion (a queued job in production).
- **Dashboard:** Screenshots section in the office settings (interval, random moment, space used; turning them on raises the consent version for the OIC) and a gallery with a viewer on the person page.
- **Desktop app:** `src/screenshot/` (schedule, capture with `xcap`, local queue in SQLite migration 003, upload after each look every 15 seconds), the updated "What this app tracks" text, a "My screenshots" page and "Last screenshot" on the Today screen.
- **Spike (task 0), on the development PC:** main screen captured at 1280x800 in a median of 306 ms (maximum 379 ms), about 81 KB a picture. One shot at most every 5 minutes, so about 0.1% CPU.

**Tests (outline)**
- A shot appears within one interval; none while paused, stopped or locked; an idle shot is taken.
- Offline for 10 minutes, then every shot arrives once; a repeated upload is not stored twice.
- An interval change reaches the app within one sync; turning screenshots on shows the consent screen again.
- A manager sees the shots of their own branch and gets 403 for anyone else's; the person sees their own; a signed-out request gets 401; no file path is ever in an API answer.
- Wrong file type, oversized image, future or very old time, and screenshots switched off are all refused.

---

### Phase 11 — Reports

**Tasks**
1. Laravel `GET /api/v1/reports/daily?from&to&uid?` (manager, scoped to `HierarchyService::allDescendantIds()` same as §10's employee routes), built from `daily_summaries` only (capped at 92 days per request to keep queries fast).
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

### Phase 12 — Organizations and custom roles (multi-tenant)

**Why:** the boss said other government offices will use the platform and they do not share one hierarchy. Decisions of 2026-09-26: one database with an `organization_id` on every tenant row; **custom roles with permission checkboxes** made by each organization; login by email only (the account carries its organization); only superadmins create organizations; a new organization starts with only its Admin role and the Admin builds everything else; superadmins have all access, with their own limited permissions, and their reading is not logged (§9.4).

**Built:**
- Migrations: `organizations`, `roles`, `organization_settings` (was `office_settings`), `platform_settings`, `organization_id` on every tenant table, `users.role_id` and the superadmin columns; the old single office moves into the first organization ("Main Office", with roles made from the old fixed ones) by migration `2026_09_27_000003`, tested against a database in the old shape and run on the real MySQL dev database.
- API: the permission catalog, `AccessService` (permissions, reach, anti-escalation), `BelongsToOrganization` scoping, roles CRUD, people rules without tiers, the platform layer (organizations, their admins, superadmins, platform settings and audit, opening an office), suspension, screenshot files under `org_<id>/`, `tracker:make-superadmin` and `tracker:make-organization`.
- Dashboard: the sidebar and every page follow the person's permissions; new Roles page; People page without tiers; the platform pages; the organization pages reused for an opened office with a banner.
- Desktop app: reads the new `Me` (organization, settings) and an older stored one; copy says "organization".
- Tests: PHP suite (`TenantIsolationTest`, `RoleManagementTest`, `RoleAssignmentTest`, `PlatformTest`, `OfficeToOrganizationMigrationTest`, `AccessServiceTest` and the reworked older ones), Rust DTO tests, and the browser suite with two organizations.

**Added afterwards (2026-09-26):** import of people from a CSV file (above); a superadmin's name and email can be edited (`PATCH /platform/superadmins/{id}`, audited as `superadmin.updated`, an email change signs them out); an organization's timezone can be edited from its profile (`organization.timezone_changed`); the organization profile shows how many people sent time in the last 7 days and the last upload; the Roles form is a drawer like the superadmin form. Then the import was made truly all-or-nothing (one transaction), its emails go to the queue, a manager may be a later row of the same file, and changing an organization's timezone (Settings or the platform edit) queues `RebuildDaysJob`, which puts every stored session on its new day and rebuilds `daily_summaries` from the sessions (`SummaryService::rebuildForOrganization`); a sync that was already running with the old timezone during the rebuild can leave one batch on the old boundary.

**Not included (ask if wanted):** self-signup; per-organization subdomain, branding or mail server; seats, quotas or billing; deleting or exporting an organization (suspending keeps everything); permissions with their own reach (one scope per role); the same email in two organizations; a separate unit tree (division / section).

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
- [ ] **Authorization:** every route has a permission check (`permission:` / `self-or-visible:` / `platform:`); `/employees/{id}` uses the self-or-visible check with the reach of the role (§9.1); nobody gives more than they have; covered by tests.
- [ ] **Tenant isolation:** every tenant table carries `organization_id`; every model is limited to the request's organization; another organization's ids are 404 on every route; `TenantIsolationTest` and the browser isolation spec pass (§9.5).
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
- [ ] **Audit logs:** changes and timeline / screenshot views recorded; kept permanently; readable by whoever holds `audit.view` in the organization, and platform actions by `platform.audit.view`. A superadmin only looking at an office is not logged (decision of 2026-09-26).
- [ ] **Data retention:** the server keeps all data permanently (no pruning). Only the employee PC's local SQLite purges already-synced rows after 7 days.
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
- **Virtual machine detection (2026-09-28):** the desktop app reads only the computer's manufacturer and model, the BIOS vendor, the hypervisor bit (never on its own) and whether the session is Remote Desktop, and reports `physical`, `virtual_machine` or `remote_session` with each sync (`status.environment`). The server keeps the live value and the strongest non-physical value of each day. It is a **flag for a manager to look into, never a reason to refuse tracking** (a hardened virtual machine can hide itself, a hardware mouse jiggler cannot be seen at all, and honest staff work on virtual desktops). It is **on for everyone**; a superadmin with `organizations.detection.manage` can switch it off or on for one person (the agent is told in `commands.detectionEnabled` on the next sync). Only **superadmins who may look inside the office and the holders of the organization's built-in Admin role** see the flags; other roles never receive them from the API. Organizations must raise the consent version so people accept the new line in "What we track". Counting injected (macro) input and reading the process list are not built.
- **Activity check (2026-09-28):** finds input made by software and jigglers, and says why. **What the desktop app reads:** counts of key presses, clicks and mouse moves, split into "from a real keyboard or mouse" and "sent by software" (a script, macro program or auto-clicker has no device; read from Windows raw input), how regular the pauses between bursts of mouse movement are and how small the bursts are, and the seconds in which the system saw input but no hardware event arrived. **Never** which keys, never text. It also compares the names of running programs with a list of known macro programs (AutoHotkey, TinyTask and similar, `config/integrity.php`, sent to the app in every sync answer) **on the PC**; only names from that list leave it. **What the server does:** `IntegrityService` turns a day's chunks into a level (`none`, `review`, `strong`) with plain reasons: software input, activity without hardware input, a macro program running, a robotic mouse pattern (a jiggler or scripted wiggle) and hours of mouse movement only in one window. There is no percentage and **tracked time is never changed**. In a virtual machine or remote session software-only signs cap at "review", because remote-control and accessibility tools send software input too. **Who sees it:** the same people as the VM flag (superadmins who may look inside the office, the built-in Admin role), and the same per-person switch (`organizations.detection.manage`) turns the whole check off; switching off clears what was stored. **Limits:** a device that presents itself as a real keyboard and mouse at driver level, and anything on another computer, cannot be seen; a hardware jiggler is only caught by its pattern. Tune the numbers in `config/integrity.php` and run `php artisan tracker:reevaluate-days`. Organizations must raise the consent version so people accept the new lines in "What we track".
- **What is NOT tracked:** keystrokes, typed text, mouse movements, webcam, microphone, file contents, websites. Screenshots are taken only if the OIC has turned them on (main screen only, at the chosen interval, with the consent text saying so).
- **When:** only while tracking is on (the tray icon shows this). Nothing is tracked while paused, not tracking, locked or asleep.
- **Who can see it:** people whose role has the permission and reaches you (a role reaches only the person, their team below them in the reporting line, or the whole organization, §9.1). Platform superadmins may open an office if they were given that permission; what they only look at is not logged, what they change is. Other organizations never see it. You can always see your own data in the app.
- **How long it's kept:** permanently on the office server.

**Safeguards**
- No hidden mode. The tray icon is always visible while tracking.
- Window titles can be turned off office-wide (`APP_ONLY`). The OIC should consider this if titles might contain private information (email subjects, document names).
- Everyone can pause their own tracking.
- No productivity scores.
- Views of other people's timelines by the organization's own people are logged, and only roles with `audit.view` can read that log. A superadmin only looking is not logged (decision of 2026-09-26); their changes are.
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
- [ ] The database is not reachable from outside the server.
- [ ] Rate limiting and input validation are on.
- [ ] The office server is HTTPS-only with a valid, auto-renewing certificate.

**Dashboard**
- [ ] Everyone can sign in and sees only what their role's permissions allow; a superadmin sees only what their platform permissions allow.
- [ ] Overview with live status (tracking / idle / not tracking / offline) and today's totals, limited to the caller's reach.
- [ ] Employee table: Name, Role, Status, Tracked, Active, Idle, Current app, Last activity.
- [ ] Employee page: totals, app breakdown, timeline, session list — reachable only within the caller's reach (or the person themselves).
- [ ] Today / Yesterday / pick a date.
- [ ] Add a person (only with a role the caller may give) and deactivate/reactivate anyone in reach; roles made by each organization (Roles page); superadmins create organizations and their admins.
- [ ] Organization settings and audit log for whoever holds those permissions; platform settings and platform audit log for superadmins.

**Process**
- [ ] All test plans for Phases 0–8 pass.
- [ ] Security checklist (§15) complete.
- [ ] 1-week pilot done with no data-loss bugs open.
- [ ] `docs/SETUP.md` and `docs/RELEASE.md` written.

**Not in the MVP:** screenshots (Phase 10, built after the MVP), reports/CSV, macOS/Linux.
**Not in the project at all:** website tracking.

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
11. **Phase 10** — Screenshots (on the office server, controlled by each organization's settings).
12. **Phase 12** — Organizations and custom roles (multi-tenant), then people import from a CSV file and the superadmin profile pages. Later: the security pass and pilot with one real office (Phase 7), deployment and `docs/RELEASE.md`.

*Phase 9 (website tracking) is dropped.*

**Rules for whoever implements this:**
- Finish and test one phase before starting the next.
- Keep all Windows-specific code in `platform/win.rs`.
- Put every new API shape in `packages/shared` first.
- If this plan and the code disagree, update this plan in the same commit.
