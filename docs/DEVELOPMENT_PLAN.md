# Office Time Tracker — Development Plan

> **How to use this document:** Build the project one phase at a time, in order. Each phase lists its tasks, what "done" looks like, and a manual test plan. Do not start a phase until the previous phase's tests pass.

---

## 0. Decisions Already Made

These are fixed. Do not change them without asking the project owner.

| Topic | Decision |
|---|---|
| Who uses it | **One office only.** Internal tool, not a public product. No sign-up page. |
| Accounts | An **Admin creates employee accounts** from the dashboard. |
| Roles | **Admin** and **Employee** only. |
| Database | **Firestore only** (server). SQLite only on the employee's computer. |
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
- Every 2 minutes it **sends new sessions** to our server (a **Cloudflare Worker**), which checks who is sending and saves them to **Firestore**.
- Admins open a **web dashboard** to see who is working now, daily totals, which apps were used, and a timeline.

The system reports **facts** (time, apps, idle). It never calculates a "productivity %".

---

## 2. Architecture

```text
EMPLOYEE COMPUTER (Windows)
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
                    ┌──────────────────────────┐
ADMIN BROWSER       │ Cloudflare Worker (API)  │
┌──────────────┐    │ - checks login token     │
│ Nuxt         │───►│ - checks role            │───► Firestore
│ dashboard    │    │ - checks data            │
└──────────────┘    │ - saves / reads data     │
                    └──────────────────────────┘
        Firebase Authentication = who you are (login)
```

**Key rules**

1. **Only the Worker talks to Firestore.** The desktop app and dashboard never touch Firestore directly. Firestore security rules block all direct access.
2. **The Rust engine does the tracking and the syncing.** The Vue screens only display and send button clicks. This means tracking and syncing keep running when the window is hidden.
3. **Local first.** Everything is saved in SQLite before it is sent.
4. **The server never trusts the app** about who the user is or what role they have. It reads that from the login token and the `users` document.

---

## 3. Technology Stack

| Part | Tools | Notes |
|---|---|---|
| Desktop app | Tauri 2, Rust, Nuxt 4 (SPA mode, `ssr: false`), Vue 3, TypeScript, Tailwind | |
| Local storage | SQLite via `rusqlite` (bundled) | |
| Rust crates | `windows` (Win32 APIs), `rusqlite`, `tokio`, `reqwest` (rustls), `serde`, `uuid` (v7), `keyring`, `tracing` + `tracing-appender`, `chrono` + `chrono-tz` | |
| Tauri plugins | `single-instance`, `autostart`, `updater`, `notification`, tray icon (built in) | |
| Server | Cloudflare Workers, TypeScript, **Hono** (tiny router made for Workers), **zod** (input checks), **jose** (token checks) | Hono is chosen because it is small, typed and standard on Workers. It is not a Node server. |
| Login | Firebase Authentication (email + password) | |
| Database | Firestore (via its REST API from the Worker) | The Firebase Admin SDK does not run on Workers, so we call the REST API with a service account. |
| Dashboard | Nuxt 4 (SPA mode), Vue 3, TypeScript, Tailwind, Firebase JS SDK (login only) | Hosted on Cloudflare (Workers static assets or Pages). |
| Tooling | pnpm workspaces, Wrangler, Firebase CLI, Tauri CLI, GitHub | |
| App updates | Cloudflare R2 (installer + update files only) | Stays inside R2's free tier (10 GB); we keep only the last 3 versions (~10–15 MB each). |

**Important:** The desktop app must be built and tested on a **real Windows 10/11 machine** (or Windows VM). The Worker and dashboard can be developed on any OS.

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
│   │       │   │   ├── client.rs       # HTTP calls to the Worker
│   │       │   │   └── worker.rs       # background sync loop
│   │       │   ├── auth.rs             # login, token refresh, Credential Manager
│   │       │   └── logging.rs
│   │       ├── Cargo.toml
│   │       └── tauri.conf.json
│   ├── dashboard/              # Admin web dashboard (Nuxt)
│   │   ├── app/pages/          # login, index, employees/[id], employees/manage, settings, audit
│   │   └── nuxt.config.ts
│   └── worker/                 # Cloudflare Worker API
│       ├── src/
│       │   ├── index.ts        # Hono app + routes
│       │   ├── middleware/     # auth.ts, rateLimit.ts, agentVersion.ts
│       │   ├── routes/         # me.ts, agent.ts, employees.ts, admin.ts
│       │   └── lib/
│       │       ├── firebaseAuth.ts   # verify ID tokens
│       │       ├── googleToken.ts    # service account → access token
│       │       ├── firestore.ts      # small REST client
│       │       ├── summaries.ts      # add sessions into daily totals
│       │       ├── timeline.ts       # merge chunks for display
│       │       └── audit.ts
│       ├── test/
│       └── wrangler.toml
├── packages/
│   └── shared/                 # TS types + zod schemas used by worker, dashboard, agent UI
│       └── src/ (api.ts, session.ts, roles.ts)
├── firebase/
│   ├── firestore.rules         # deny everything
│   ├── firestore.indexes.json
│   └── firebase.json
├── docs/
│   └── DEVELOPMENT_PLAN.md     # this file
├── .github/workflows/          # CI: lint, typecheck, test, build
├── pnpm-workspace.yaml
└── package.json
```

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
  id               TEXT PRIMARY KEY,         -- UUID v7, made on the PC. Also the Firestore doc id.
  user_id          TEXT NOT NULL,            -- Firebase uid of the logged-in employee
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

## 8. Firestore Design

There is only one office, so there is no `organizations` level.

```text
users/{uid}
  name, email
  role: "ADMIN" | "EMPLOYEE"
  status: "ACTIVE" | "DEACTIVATED"
  deactivatedAt: timestamp | null
  consentVersion: number | null, consentAcceptedAt: timestamp | null
  createdAt, createdBy

status/{uid}                              # one live-status doc per employee
  state: "ACTIVE" | "IDLE" | "PAUSED" | "AWAY" | "NOT_TRACKING"
  currentApp: string | null, idleAppName: string | null
  deviceId, agentVersion
  since: timestamp                         # when this state began
  lastSeenAt: timestamp                    # server time of last sync
  clockSkewSeconds: number                 # PC clock minus server clock
  trackingDeviceSince: timestamp           # when this device took over tracking

sessions/{sessionId}                      # sessionId = UUID made on the PC
  uid, deviceId
  type: "APPLICATION" | "IDLE"
  appName, appKey, processName, windowTitle, idleAppName
  startedAt, endedAt: timestamp
  durationSeconds: number
  day: "YYYY-MM-DD"                        # office-timezone day of startedAt
  clockChanged: bool
  receivedAt: timestamp
  expireAt: timestamp                      # endedAt + 30 days (TTL)

dailySummaries/{uid}_{YYYY-MM-DD}
  uid, day
  trackedSeconds, activeSeconds, idleSeconds
  apps: { [appKey]: seconds }              # active seconds per app
  appNames: { [appKey]: "Visual Studio Code" }
  firstActivityMs, lastActivityMs: number  # epoch ms (so min/max transforms work)
  expireAt: timestamp                      # day + 90 days (TTL)

devices/{deviceId}
  uid, computerName, agentVersion, firstSeenAt, lastSeenAt

settings/office
  timezone: "e.g. Asia/Manila"             # defines what "a day" is
  idleThresholdSeconds: 300
  windowTitleMode: "FULL" | "APP_ONLY"     # default FULL
  minAgentVersion: "1.0.0"
  consentVersion: 1

auditLogs/{autoId}
  actorUid, action, targetUid, details, at
  expireAt                                  # at + 365 days
```

**`appKey`:** process name in lowercase, without `.exe`, with any character other than `a-z 0-9 _` replaced by `_` (e.g. `code`, `chrome`). This is safe as a Firestore map key.

**Indexes (`firestore.indexes.json`)**
- `sessions`: `uid ASC, startedAt ASC`
- `auditLogs`: `at DESC`

**TTL policies (retention):** turn on Firestore TTL for `sessions.expireAt`, `dailySummaries.expireAt` and `auditLogs.expireAt`. Firestore deletes expired docs automatically, usually within a day or so after they expire.

**Security rules (`firestore.rules`):** deny everything. Only the Worker (service account) can read or write.

```text
rules_version = '2';
service cloud.firestore {
  match /databases/{database}/documents {
    match /{document=**} { allow read, write: if false; }
  }
}
```

---

## 9. Authentication and Authorization

### 9.1 Roles

| Role | Can do |
|---|---|
| **EMPLOYEE** | Log in to the desktop app. Start / pause / stop own tracking. See own data. **Cannot** open the admin dashboard or see anyone else. |
| **ADMIN** | Everything an employee can do, **plus:** use the dashboard, see all employees, add / deactivate employees, change roles, change office settings, read the audit log. |

### 9.2 How login works

- **Desktop app:** the Vue login screen sends email + password to Rust (`invoke("login")`). Rust calls Firebase's REST sign-in (`accounts:signInWithPassword`). Rust keeps the **refresh token in Windows Credential Manager** and gets new ID tokens itself (`securetoken.googleapis.com`). The Vue screens never hold tokens. The app has no Firebase SDK.
- **Dashboard:** Firebase JS SDK, email + password.
- **Forgot password:** "Forgot password" button → Firebase password-reset email.
- **First admin (one-time, manual):** create the user in the Firebase console, then create `users/{uid}` in the Firestore console with `role: "ADMIN"`, `status: "ACTIVE"`. Write these steps in `docs/SETUP.md`.
- **Adding employees:** an admin enters name + email in the dashboard. The Worker creates the Firebase user (Identity Toolkit admin API with a random password), creates `users/{uid}`, and sends a Firebase password-reset email so the employee sets their own password.

### 9.3 What the Worker checks on every request

1. `Authorization: Bearer <Firebase ID token>` exists.
2. Token signature is valid, checked with `jose` against Google's public keys (`https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com`). Keys are cached using their `Cache-Control` time.
3. `aud` = our Firebase project ID. `iss` = `https://securetoken.google.com/<projectId>`. `exp` is in the future. `sub` is not empty.
4. Load `users/{sub}`. It must exist and be `ACTIVE` (with one exception for unsent data, see §10). Cache it in memory for 60 seconds.
5. Role check for the route.
6. **The uid always comes from the token.** Any `uid`, `role` or `employeeId` in the request body is ignored. For `/employees/:id/...` routes: ADMIN → any id. EMPLOYEE → only their own id, otherwise 403.

---

## 10. API Design (Cloudflare Worker)

Base URL: `https://api.<your-domain>`. All responses are JSON. Errors look like `{ "error": { "code": "FORBIDDEN", "message": "..." } }`.

| Method & path | Who | Purpose |
|---|---|---|
| `GET /health` | anyone | `{ ok: true, version }` |
| `GET /api/v1/me` | logged in | Own profile, role, office settings, whether consent is needed |
| `POST /api/v1/me/consent` | logged in | Record consent `{ consentVersion }` |
| `POST /api/v1/agent/sync` | logged in (agent) | Sends status + up to 100 closed sessions. Gets back results + commands. |
| `GET /api/v1/employees` | admin | List of employees with live status and today's totals |
| `GET /api/v1/employees/:id/summary?from=YYYY-MM-DD&to=YYYY-MM-DD` | admin, or self | Daily totals per day (max 31 days) |
| `GET /api/v1/employees/:id/timeline?day=YYYY-MM-DD&cursor=` | admin, or self | Merged timeline segments for one day (max 500 per page) |
| `POST /api/v1/admin/employees` | admin | Create employee `{ name, email, role }` |
| `PATCH /api/v1/admin/employees/:id` | admin | Change `name`, `role`, or `status` (deactivate / reactivate) |
| `GET /api/v1/admin/settings` / `PUT` | admin | Read / change office settings |
| `GET /api/v1/admin/audit?cursor=` | admin | Audit log, newest first, 50 per page |

**Why one `/agent/sync` endpoint instead of separate "sessions" and "batch" endpoints:** the app sends one request every 2 minutes carrying both its live status and any new sessions. One request instead of two halves the traffic and the Firestore cost. A single session is just a batch of one.

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

Other responses: `401` (token bad/expired – refresh and retry once), `403 ACCOUNT_DEACTIVATED`, `426 UPGRADE_REQUIRED`, `429` (too many requests – wait), `5xx` (retry later).

**Validation (zod, in `packages/shared`)** — a session is **rejected** (not retried) if:
- `id` is not a UUID, or `type` is not one of the two values;
- `endedAt ≤ startedAt`, or `durationSeconds` is not 1–660, or it differs from `endedAt − startedAt` by more than 5 seconds;
- `endedAt` is more than 10 minutes in the future (server time);
- `startedAt` is older than 30 days (`TOO_OLD`);
- text fields are too long (`appName`/`processName` 128, `windowTitle` 512).
- more than 100 sessions in the request → the whole request gets `400`.

**What the Worker does, step by step**
1. Check the auth header, rate limit, and agent version (`426` if below `settings.minAgentVersion`).
2. Validate the body.
3. If `windowTitleMode = APP_ONLY`, set every `windowTitle` to `null`.
4. Firestore transaction:
   1. `beginTransaction`.
   2. `batchGet`: all `sessions/{id}` in the request + `status/{uid}`.
   3. Sessions that already exist → `duplicates`. New ones → `accepted`.
   4. **One tracking PC rule:** if `status.state` is ACTIVE/IDLE and `status.deviceId` is a different PC that synced in the last 5 minutes → this PC takes over (`deviceId` = this one, `trackingDeviceSince` = now). The old PC gets `commands.stopTracking = true, stopReason: "STARTED_ON_OTHER_PC"` on its next sync, and its sessions starting after `trackingDeviceSince` are rejected (`OTHER_DEVICE_ACTIVE`).
   5. **Deactivated user:** sessions that started before `deactivatedAt` are accepted. Newer ones are rejected. Respond with `commands.signOut = true`.
   6. `commit` writes: create each new session (with precondition `exists: false`), update `status/{uid}`, and add each new session into its daily summary (see below).
   7. If the commit fails with `ABORTED` (a clash), retry the whole transaction once.
5. Return the response.

**Adding a session into daily summaries (`lib/summaries.ts`):**
- Work out which office-timezone day(s) the session covers. If it crosses midnight, split the seconds between the two days.
- For each day, update `dailySummaries/{uid}_{day}` using Firestore field transforms:
  - `increment` `trackedSeconds`, plus `activeSeconds` **or** `idleSeconds`;
  - APPLICATION: `increment apps.<appKey>` and set `appNames.<appKey>`;
  - `minimum firstActivityMs`, `maximum lastActivityMs`;
  - set `expireAt` = day + 90 days.
- Because only **new** sessions are added (step 4.3), a repeated upload never counts twice.

### 10.2 Idempotency (no duplicates) — summary

1. The PC creates a UUID for every session. The UUID never changes, even across retries.
2. That UUID is the Firestore document ID (`sessions/{uuid}`).
3. Inside a transaction, the Worker checks which UUIDs already exist. It creates only the missing ones, and only those are added to the totals.
4. Duplicates are reported back as `duplicates`. The app treats them like `accepted` (mark as sent).
5. The creates also use the precondition "document must not exist", as a second safety net.

### 10.3 Other Worker details

- **Firestore access (`lib/googleToken.ts`, `lib/firestore.ts`):** sign a JWT with the service account's private key (`jose`, RS256), exchange it at `https://oauth2.googleapis.com/token` for an access token (scope `https://www.googleapis.com/auth/datastore`), and cache it until 5 minutes before it expires. `firestore.ts` only needs: `get`, `batchGet`, `runQuery`, `beginTransaction`, `commit`, and helpers to convert JS values to and from Firestore's REST format.
- **Rate limiting:** Cloudflare Workers Rate Limiting binding, keyed by uid. `/agent/sync`: 30 requests per minute. Other routes: 120 per minute.
- **CORS:** allow only the dashboard's origin. (The desktop app calls from Rust, so it needs no CORS.)
- **Timeline (`lib/timeline.ts`):** query `sessions` where `uid == id`, `startedAt ≥ dayStart − 11 min`, `startedAt < dayEnd`, ordered by `startedAt`. Clip to the day. Merge neighbouring pieces that have the same type and app and a gap of 5 seconds or less. Return segments `{ type, label, startedAt, endedAt, seconds }`, where `label` is e.g. `"Visual Studio Code"` or `"Idle (in Zoom)"`.
- **Employee list:** read all `users` (office size, so fine), then `batchGet` their `status` docs and today's `dailySummaries`. Show "Offline" if `lastSeenAt` is more than 5 minutes ago while the state says tracking.
- **Audit log:** write an entry when an admin creates, changes or deactivates an employee, changes settings, or opens an employee's timeline.
- **Logging:** `console.log` structured JSON (Workers Logs). Never log tokens, keys, or full window titles.

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
- `401` → refresh the token and retry once. If refreshing fails → show "Please log in again" and keep the data.
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
1. Create the monorepo (§4): `pnpm-workspace.yaml`, root `package.json` scripts: `dev:agent`, `dev:dashboard`, `dev:worker`, `lint`, `typecheck`, `test`.
2. Move the spike into the final `apps/agent` structure.
3. Create `apps/dashboard` (Nuxt 4, `ssr: false`, Tailwind).
4. Create `apps/worker` (Hono, `wrangler.toml` with `dev` and `production` environments). Add `GET /health`.
5. Create `packages/shared` with the zod schemas and TS types from §10.
6. TypeScript `strict: true` everywhere. ESLint + Prettier with a shared config.
7. Rust: `cargo fmt`, `cargo clippy -- -D warnings`.
8. Create **two Firebase projects**: `tracker-dev` and `tracker-prod`. Turn on Email/Password auth and Firestore in both. (We use a real dev project instead of emulators, so the auth code has no special test mode.)
9. Create a Google service account with the "Cloud Datastore User" role for each project. Store its email and private key as Wrangler secrets (`wrangler secret put GOOGLE_SA_EMAIL`, `GOOGLE_SA_PRIVATE_KEY`). **Never commit them.**
10. Deploy `firestore.rules` (deny all) and `firestore.indexes.json` with the Firebase CLI.
11. Config:
    - agent build: `API_BASE_URL`, `FIREBASE_API_KEY`, `FIREBASE_PROJECT_ID` (public values, no secrets);
    - dashboard: `NUXT_PUBLIC_API_BASE`, `NUXT_PUBLIC_FIREBASE_*`;
    - worker `wrangler.toml` vars: `FIREBASE_PROJECT_ID`, `DASHBOARD_ORIGIN`.
    - Add `.env.example` files. Put `.env*` (except examples) in `.gitignore`.
12. Restrict the Firebase Web API key in Google Cloud Console to the Identity Toolkit and Token Service APIs.
13. GitHub Actions: lint, typecheck, tests, and `cargo clippy` + `cargo test` on `windows-latest`.
14. **Start getting a Windows code-signing certificate now** (it can take weeks). Options: Azure Trusted Signing (cheapest, if the company qualifies) or an OV certificate from a certificate authority.
15. Write `docs/SETUP.md`: how to install, run and deploy each part.

**Deliverables**
- A repo where `pnpm install && pnpm lint && pnpm typecheck && pnpm test` pass.
- The Worker is deployed to dev and `/health` works.
- The dashboard runs locally and shows a placeholder page.

**Tests**

```text
Test 1.1 [N] Fresh setup
1. Clone the repo into a new folder. Follow docs/SETUP.md.
Expected: everything installs and runs.
PASS: all three apps start without undocumented steps.

Test 1.2 [N] Quality checks
1. Run `pnpm lint`, `pnpm typecheck`, `pnpm test`, `cargo clippy`.
Expected: all pass.
PASS: 0 errors.

Test 1.3 [N] Worker health
1. Open https://<dev-worker>/health.
Expected: {"ok":true,...}
PASS: 200 response.

Test 1.4 [S] No secrets in git
1. Run `git grep -i "private_key\|BEGIN PRIVATE"`.
Expected: no results (except docs mentioning the variable names).
PASS: no real keys in the repo.

Test 1.5 [S] Firestore is locked
1. In the browser console of any page, use the Firebase JS SDK to read `users`.
Expected: permission denied.
PASS: direct access is blocked.

Test 1.6 [N] CI
1. Push a branch with a lint error.
Expected: CI fails. Fix it → CI passes.
PASS: CI catches the error.
```

---

### Phase 2 — Login, Users and Roles

**Tasks**
1. Worker `lib/firebaseAuth.ts`: verify ID tokens (§9.3) with `jose`. Cache the keys.
2. Worker `lib/googleToken.ts` + `lib/firestore.ts` (§10.3).
3. Worker middleware `auth.ts`: token → `users/{uid}` → attach `{ uid, role, status }` to the request. 401 if the token is bad. 403 if the user is missing or deactivated.
4. Helper `requireRole("ADMIN")` and `requireSelfOrAdmin(paramId)`.
5. Routes: `GET /api/v1/me`, `POST /api/v1/me/consent`, `POST/PATCH /api/v1/admin/employees`, `GET/PUT /api/v1/admin/settings`, `GET /api/v1/admin/audit`.
6. Create employee: Identity Toolkit admin API → create user → `users/{uid}` → send password-reset email → audit log entry. If the email already exists → `409`.
7. Deactivate: set `status: DEACTIVATED`, `deactivatedAt`, and disable the Firebase user (so they can't get new tokens). Reactivate does the reverse.
8. An admin cannot deactivate themselves or remove the last admin (`400`).
9. Seed `settings/office` with defaults (timezone, idle 300 s, FULL titles, minAgentVersion, consentVersion 1).
10. Dashboard: login page (Firebase JS SDK). After login call `/me`. If not ADMIN → sign out and show "This dashboard is for admins only." Route guard on every page.
11. Dashboard: `employees/manage` page — list, add, change role, deactivate / reactivate.
12. Desktop app: login screen → `invoke("login")` → Rust REST sign-in → refresh token saved in Credential Manager → `/me`.
13. Desktop app: **consent screen** on first login (and whenever `consentVersion` goes up). It lists exactly what is tracked (§16). Tracking can't start until it's accepted.
14. Desktop app: "Forgot password" link.
15. `docs/SETUP.md`: the one-time steps to create the first admin.
16. Worker unit tests (vitest) for: token checks (expired, wrong audience, wrong issuer, bad signature), role checks, self-or-admin checks.

**Deliverables**
- Admin can log in to the dashboard and add employees.
- Employees get an email, set a password, log in to the desktop app and accept consent.

**Tests**

```text
Test 2.1 [N] Admin login
1. Log in to the dashboard as the first admin.
Expected: you see the dashboard.
PASS: logged in.

Test 2.2 [N] Add employee
1. Dashboard → Manage → Add "Test Employee" with your second email address.
2. Check that inbox.
3. Set a password via the email link.
4. Log in to the desktop app with it.
Expected: email arrives, login works, consent screen shows.
PASS: employee can log in.

Test 2.3 [S] Employee can't use the dashboard
1. Log in to the dashboard as the employee.
Expected: "This dashboard is for admins only." and signed out.
PASS: no employee data visible.

Test 2.4 [S] Employee can't call admin APIs
1. As the employee, copy the ID token (desktop app dev log, or sign in with the SDK in a scratch page).
2. curl -H "Authorization: Bearer <token>" <worker>/api/v1/employees
3. curl the same token to POST /api/v1/admin/employees
Expected: 403 for both.
PASS: both 403.

Test 2.5 [S] Can't see other employees
1. Create employees A and B.
2. With A's token: GET /api/v1/employees/<B uid>/summary?from=...&to=...
Expected: 403.
PASS: 403.

Test 2.6 [S] Fake role in the body is ignored
1. With the employee's token, PATCH /api/v1/admin/employees/<own uid> with {"role":"ADMIN"}.
Expected: 403. Role is still EMPLOYEE in Firestore.
PASS: no change.

Test 2.7 [S] Bad tokens
1. Call /api/v1/me with no token, a random string, an expired token, and a token from another Firebase project.
Expected: 401 each time.
PASS: all 401.

Test 2.8 [N] Deactivate
1. Admin deactivates the employee.
2. Employee tries to log in to the desktop app.
Expected: login fails with "Your account is deactivated."
PASS: can't log in.

Test 2.9 [N] Consent required
1. New employee logs in. Try to start tracking without accepting.
Expected: impossible. Start is only available after accepting.
PASS: consentAcceptedAt is saved in users/{uid} after accepting.

Test 2.10 [S] Token not stored in plain files
1. After login, search %APPDATA% for the refresh token text.
Expected: not found. It's in Windows Credential Manager.
PASS: not in any file.

Test 2.11 [N] Last admin protection
1. As the only admin, try to deactivate yourself.
Expected: error, nothing changes.
PASS: still admin.

Test 2.12 [N] Audit
1. Open Dashboard → Audit.
Expected: entries for "employee created", "employee deactivated".
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

### Phase 4 — Worker Sync and Firestore

**Tasks**
1. `packages/shared`: zod schema for the sync request/response (§10.1).
2. Worker `routes/agent.ts`: `POST /api/v1/agent/sync`, exactly as in §10.1 (transaction, duplicates, one-PC rule, deactivated rule).
3. `lib/summaries.ts`: add sessions into daily totals, splitting at midnight in the office timezone.
4. Middleware: rate limit (Workers Rate Limiting binding), `X-Agent-Version` check (`426`).
5. Firestore TTL policies on `expireAt` (sessions 30 d, summaries 90 d, audit 365 d). Set them in the console or with `gcloud`, and write the steps in `docs/SETUP.md`.
6. Rust `sync/client.rs` + `sync/worker.rs` (§11.1): batching, retry wait times, 401 refresh, commands, settings.
7. Rust `auth.rs`: refresh the ID token 5 minutes before it expires.
8. Detect "internet is back" (a successful `/health` call, checked every 30 s while offline) → sync now.
9. `logout` command (§6.3): stop, sync with a 30 s limit, then sign out or show the offline message. Data stays tied to the user id.
10. When a user logs in on a PC that holds unsent data from **another** user: keep it; it is sent when that user logs in again.
11. Worker tests (vitest):
    - valid batch → accepted;
    - same batch twice → second time all `duplicates`, totals unchanged;
    - bad sessions → rejected with the right reason;
    - midnight split;
    - one-PC takeover;
    - deactivated user rule.

**Deliverables**
- Sessions from the desktop app appear in Firestore within ~2 minutes (and ≤ 10 minutes for long sessions), with correct daily totals.

**Tests**

```text
Test 4.1 [N] Sessions reach Firestore
1. Track 5 minutes across 2 apps. Wait 3 min.
2. Open the Firestore console → sessions.
Expected: the same sessions (same ids) as the local debug page.
PASS: all present, local rows marked SYNCED.

Test 4.2 [N] Daily summary
1. After 4.1, open dailySummaries/<uid>_<today>.
Expected: trackedSeconds = sum of sessions; apps per app correct.
PASS: matches within 1 s.

Test 4.3 [N] Live status
1. Start tracking. Look at status/<uid> in Firestore. Go idle past the limit. Pause. Stop.
Expected: state changes ACTIVE → IDLE → PAUSED → NOT_TRACKING, each within ~2 min.
PASS: all states seen.

Test 4.4 [D] Duplicate upload
1. Take a sync request (from the log, or build one with curl) and send it twice.
Expected: the 2nd response lists all ids under "duplicates". Summary totals don't change.
PASS: no double count.

Test 4.5 [F] Offline then online
1. Wi-Fi off. Track for 30 min. Wi-Fi on.
Expected: within ~1 minute of reconnecting, all sessions upload. Totals correct.
PASS: count in Firestore = count locally; no duplicates.

Test 4.6 [F] Worker down
1. Point the app at a wrong API URL (dev build) or deploy a Worker that returns 500.
2. Track 10 minutes. Restore the Worker.
Expected: tracking continues; the app shows "Sync pending"; data uploads once the Worker is back.
PASS: nothing lost.

Test 4.7 [F] Firestore failure
1. In the dev project, temporarily remove the service account's Firestore role.
2. Track 5 minutes. Put the role back.
Expected: Worker returns 5xx; the app retries later; data arrives after the fix.
PASS: nothing lost, nothing duplicated.

Test 4.8 [F] Token expiry
1. Leave the app tracking for 2+ hours (tokens last 1 hour).
Expected: syncing continues without asking to log in.
PASS: no 401 errors that stop syncing.

Test 4.9 [N] Logout online
1. Track 3 min. Log out right away.
Expected: "Sending your data…" then signed out. All sessions in Firestore.
PASS: 0 pending sessions.

Test 4.10 [F] Logout offline
1. Wi-Fi off. Track 3 min. Log out.
Expected: "You're offline, your data will be sent next time you log in." Signed out.
2. Wi-Fi on. Log in as the same user.
Expected: the data uploads.
PASS: sessions appear in Firestore after re-login.

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
1. With employee A's token, send a sync whose body includes "uid": "<B uid>".
Expected: the extra field is ignored; sessions are saved under A.
PASS: nothing written for B.

Test 4.14 [S] Bad data
1. Send sessions with: endedAt before startedAt; duration 99999; startedAt 60 days ago; a 5,000-character title; 101 sessions.
Expected: first four rejected with reasons; the 101-session request gets 400.
PASS: nothing bad saved.

Test 4.15 [S] Deactivated during work
1. Employee tracks offline for 10 min. Admin deactivates them. Employee goes online.
Expected: sessions from before deactivation are accepted; the app then signs out.
PASS: correct sessions saved; no newer ones accepted.

Test 4.16 [F] Old app version
1. Set settings/office.minAgentVersion higher than the installed version.
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
   - idle limit and title mode (read-only, set by admin);
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

### Phase 6 — Admin Dashboard

**Tasks**
1. Worker routes: `GET /api/v1/employees`, `/employees/:id/summary`, `/employees/:id/timeline` (§10). Audit "viewed timeline".
2. **Overview page (`/`):**
   - cards: tracking now / idle now / not tracking (includes offline);
   - today's total tracked / active / idle for everyone;
   - an employee table with Employee, Status, Tracked, Active, Idle, Current app, Last activity.
   - Refresh every 60 s, only while the browser tab is visible.
3. **Employee page (`/employees/[id]`):**
   - date picker: Today / Yesterday / pick a date;
   - totals: tracked / active / idle;
   - app breakdown: top 5 apps + "Other", with times (`5h 02m`);
   - timeline: a horizontal bar from first to last activity, one colored block per segment, idle in grey with "Idle (in Zoom)" on hover, gaps left empty;
   - a session list under the timeline (time range, app, title, duration), paginated.
4. Build the timeline with plain HTML/CSS (divs with widths as percentages). No chart library.
5. Time format helper: `7h 24m`, `33m`, `45s`.
6. Show all times in the office timezone.
7. **Settings page:** idle limit (1–30 min), window title mode, office timezone, minimum app version.
8. **Audit page:** simple paginated list.

**Deliverables**
- A working admin dashboard, deployed to dev.

**Tests**

```text
Test 6.1 [N] Overview
1. Have 2 employees: one tracking, one not.
Expected: cards show 1 tracking, 1 not tracking; the table shows correct status and current app.
PASS: correct within ~2 minutes.

Test 6.2 [N] Idle status
1. Employee goes idle past the limit.
Expected: status "Idle" within ~2 minutes.
PASS: correct.

Test 6.3 [N] Offline status
1. Employee's PC loses internet while tracking.
Expected: after ~5 min the status shows "Offline (last seen HH:MM)".
PASS: correct.

Test 6.4 [D] Totals match
1. Compare an employee's dashboard totals for today with the Today screen in their desktop app.
Expected: same (±2 min for sync delay).
PASS: match.

Test 6.5 [N] App breakdown
1. Check the employee page.
Expected: app times add up to Active; the top 5 plus Other are shown.
PASS: sums correct.

Test 6.6 [N] Timeline
1. Compare the timeline with the employee's own timeline.
Expected: same blocks in the same order; long sessions shown as one block (not 10-min pieces).
PASS: match.

Test 6.7 [N] Dates
1. Switch Today → Yesterday → a date 2 weeks ago → a date 2 months ago.
Expected: correct data. Dates older than 30 days show totals only, with the note "Detailed timeline is kept for 30 days."
PASS: correct.

Test 6.8 [S] Direct URL as employee
1. Log in as an employee and go to /employees/<other uid>.
Expected: blocked (admin-only message / signed out).
PASS: no data shown.

Test 6.9 [S] Deactivated admin
1. Deactivate a second admin while they have the dashboard open.
Expected: within 60 s their next request fails with 403 and they're signed out.
PASS: access stops.

Test 6.10 [P] Page speed
1. Open the overview with all employees, then an employee's busy day.
Expected: each loads in under 2 seconds.
PASS: within target.

Test 6.11 [P] Firestore reads
1. Open the overview once and check the Firestore usage tab.
Expected: about 3 reads per employee per refresh.
PASS: no unexpected big numbers.
```

---

### Phase 7 — Reliability and Security Pass

Most of these were tested in earlier phases. This phase repeats them **together, on the release build**, and fixes anything found.

**Tasks**
1. Build a release version and install it on 2–3 real employee PCs (a pilot) for 1 week.
2. Run the full edge-case list (§13) on the release build.
3. Check Worker logs for errors and slow requests. Fix the top issues.
4. Check Firestore usage against §14.
5. Go through the security checklist (§15). Write down anything not done and why.
6. Test a restore: export Firestore (`gcloud firestore export`) to a bucket, and write down how to restore it.

**Deliverables**
- A pilot report: bugs found and fixed, resource numbers, Firestore costs.
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
PASS: local count = Firestore count; totals match.

Test 7.3 [S] Security sweep
1. Repeat tests 2.4–2.7, 4.13, 4.14 and 6.8 against production.
Expected: same results.
PASS: all blocked.

Test 7.4 [D] Duplicates check
1. Run a small script comparing session ids across Firestore and the local DBs.
Expected: every local SYNCED id is in Firestore exactly once.
PASS: 0 missing, 0 extra.

Test 7.5 [P] One-week resources
1. On pilot PCs, check CPU, memory and tracker.db size after a week.
Expected: within §14 targets.
PASS: all within targets.

Test 7.6 [F] Restore drill
1. Export the dev Firestore. Delete a test collection. Restore it.
Expected: data back.
PASS: restore works and the steps are written down.
```

---

### Phase 8 — Installer and Updates

**Tasks**
1. Tauri NSIS installer, **per-user install** (no admin rights needed). App name, icon, version.
2. Sign the installer and the exe with the code-signing certificate from Phase 1.
3. Uninstaller: removes the app and the autostart entry. Offer "Also delete local data" (unticked by default). Warn if there's unsent data.
4. Updater: `tauri-plugin-updater`. Generate the updater key pair; keep the **private key only in GitHub Secrets**. Host `latest.json` + installers in an R2 bucket that is publicly readable **only for the update files** (custom domain `updates.<your-domain>`). Keep only the last 3 versions so storage stays inside the free tier.
5. Check for updates at startup and every 6 hours. Install only when **not tracking**, or when the user clicks "Update now" (which stops tracking cleanly first). Resume tracking after restarting if it was on.
6. Database migrations run at startup with a backup first (§7.4). If a migration fails → restore the backup and show an error.
7. GitHub Actions release workflow: on tag `v*` → build on `windows-latest` → sign → upload to R2 → update `latest.json` → delete versions older than the last 3.
8. Worker: `minAgentVersion` (§10.1) forces very old versions to update.
9. `docs/RELEASE.md`: how to release, and how to roll back (point `latest.json` at the previous version).

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
1. Worker `GET /api/v1/reports/daily?from&to&uid?` (admin), built from `dailySummaries` only (max 92 days, to match retention).
2. Reports:
   - **Daily employee report:** one row per employee per day (tracked / active / idle, first / last activity);
   - **App usage report:** app totals for a date range;
   - **Team report:** everyone's totals for a date range.
3. CSV export (the Worker returns `text/csv`). Times as `HH:MM` and also as plain seconds.
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

Test 11.3 [S] Employee access
1. Call /api/v1/reports/daily with an employee token.
Expected: 403.
PASS: 403.

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
| 15 | Worker unavailable | Retries at 1, 2, 5, 10, then every 30 min. Nothing lost. |
| 16 | Firestore unavailable | The Worker returns 5xx; the transaction saves nothing half-done; the app retries. |
| 17 | Firebase token expires | Rust refreshes it automatically. If the refresh fails (password changed, account disabled): "Please log in again", data kept. |
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
| Worker requests | ≈ 1 every 2 min while logged in → **~250 per employee per 8-hour day** |
| Firestore writes | ≈ 1 status + new sessions + 1 summary per sync → **~600–900 per employee per day** |
| Firestore reads | ≈ 1 per new session (duplicate check) + dashboard views |
| Dashboard pages | Load in < 2 s |

**Cost note:** Firestore's free tier is 20,000 writes and 50,000 reads per day. That covers about 20 employees. Beyond that the cost is very small (cents per day for a normal office). The dashboard only refreshes while its tab is visible, to keep reads down.

**What keeps it light:** no network polling for activity (only one sync every 2 minutes); Windows calls that take microseconds; SQLite writes only when a session closes plus a tiny update every 30 s; dashboard totals read from ready-made summaries.

---

## 15. Security Checklist

- [ ] **Firebase Auth:** email/password only. Password-reset emails for new accounts; admins never see passwords.
- [ ] **Token checks:** signature, `aud`, `iss`, `exp` checked on every request. Google keys cached with their `Cache-Control` time.
- [ ] **Authorization:** every route has a role check; `/employees/:id` uses the self-or-admin check; covered by tests.
- [ ] **Never trust the client:** uid comes from the token, role from Firestore. Body fields like `uid`/`role` are ignored.
- [ ] **Deactivation works immediately:** user doc checked on every request (60 s cache), and the Firebase user is disabled.
- [ ] **Input validation:** zod on every request body and query; size limits; max 100 sessions per sync.
- [ ] **Rate limiting:** per uid on all API routes.
- [ ] **Secrets:** service account key only in Wrangler secrets; updater private key only in GitHub Secrets; nothing secret in the desktop app or the dashboard (the Firebase Web API key is public by design, but restricted to the auth APIs).
- [ ] **Firestore rules:** deny all; tested (Test 1.5).
- [ ] **HTTPS only:** Workers and the dashboard are HTTPS; Rust `reqwest` uses rustls with certificate checks on.
- [ ] **CORS:** only the dashboard origin.
- [ ] **Local token storage:** refresh token in Windows Credential Manager, never in files or SQLite.
- [ ] **Local SQLite:** stored in the user's own `%APPDATA%` (other Windows users can't read it); no passwords or tokens in it. Encryption is not in the MVP (the data is the employee's own activity).
- [ ] **R2:** only used for app update files; public read for those files only; uploads only from the GitHub release workflow.
- [ ] **Audit logs:** admin actions and timeline views recorded; kept 365 days.
- [ ] **Data retention:** TTL on sessions (30 d), summaries (90 d), audit (365 d); tested in Phase 7.
- [ ] **Logs:** no tokens, keys, passwords or window titles in logs.
- [ ] **Code signing:** installer and exe signed; updater signature checked.
- [ ] **Dependencies:** `pnpm audit` and `cargo audit` in CI.
- [ ] **Backups:** Firestore export schedule set up and a restore tested.
- [ ] **Transparency:** consent screen, tray icon always visible while tracking, "What we track" page.

---

## 16. Privacy

**What the employee is told (consent screen + "What we track" page):**

- **What is tracked:** start/stop/pause times; which app is in front and for how long; the window title (unless the office turned titles off); when you're idle (no mouse/keyboard for X minutes) and which app was on screen then.
- **What is NOT tracked:** keystrokes, typed text, mouse movements, webcam, microphone, file contents, screenshots (never), websites (unless turned on later, with new consent).
- **When:** only while tracking is on (the tray icon shows this). Nothing is tracked while paused, not tracking, locked or asleep.
- **Who can see it:** office admins. You can see your own data in the app.
- **How long it's kept:** detailed activity 30 days, daily totals 3 months.

**Safeguards**
- No hidden mode. The tray icon is always visible while tracking.
- Window titles can be turned off office-wide (`APP_ONLY`). Admins should consider this if titles might contain private information (email subjects, document names).
- Employees can pause.
- No productivity scores.
- Admins' views of employee timelines are logged.
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
- [ ] Worker verifies Firebase tokens and enforces roles on every route.
- [ ] Sync is idempotent (Test 4.4 passes).
- [ ] Daily summaries are correct, including midnight splits.
- [ ] Firestore direct access is blocked; TTL retention is on.
- [ ] Rate limiting and input validation are on.

**Dashboard**
- [ ] Admin-only login.
- [ ] Overview with live status (tracking / idle / not tracking / offline) and today's totals.
- [ ] Employee table: Employee, Status, Tracked, Active, Idle, Current app, Last activity.
- [ ] Employee page: totals, app breakdown, timeline, session list.
- [ ] Today / Yesterday / pick a date.
- [ ] Manage employees (add, change role, deactivate) and office settings.
- [ ] Audit log.

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
2. **Phase 1** — Repo, tooling, Firebase projects, Worker `/health`. **Order the code-signing certificate.**
3. **Phase 3** — Tracking engine, offline only. (Can run alongside Phase 2; it doesn't need the server.)
4. **Phase 2** — Login, users, roles, consent.
5. **Phase 4** — Sync to Firestore.
6. **Phase 5** — Employee screens.
7. **Phase 6** — Admin dashboard.
8. **Phase 7** — Pilot week + fixes.
9. **Phase 8** — Installer + updates → **MVP done.**
10. **Phase 11** — Reports (the most useful next step for an office).
11. **Phase 9** — Website tracking, only if really needed, with new consent.

**Rules for whoever implements this:**
- Finish and test one phase before starting the next.
- Keep all Windows-specific code in `platform/win.rs`.
- Put every new API shape in `packages/shared` first.
- If this plan and the code disagree, update this plan in the same commit.
