# Security review

The security pass over the checklist in `docs/DEVELOPMENT_PLAN.md` §15 (Phase 7, test 7.3), done on 2026-09-26 against the code at the commit that adds this file. It was done by reading the code and running checks against a local instance. It cannot cover a real server, real networks or a professional penetration test: those are listed at the end as still open.

Each line says what was looked at, how, and what came out. "Automatic test" means a test in the suite that fails if the behaviour breaks.

## 1. Found and fixed in this pass

| # | Finding | Risk | Fix | Test |
|---|---|---|---|---|
| 1 | The desktop login (`POST /api/v1/auth/login`) had **no rate limit**. Only the dashboard login and the reset routes were limited. | Someone could try passwords for any account without being stopped. | A `login` limiter on both logins: 8 tries a minute per account and address, 60 a minute per address (an office behind one public address is not locked out by one person's typos). | `SecurityTest` (desktop, dashboard, colleague not locked out) |
| 2 | The token the desktop app receives could call **every** dashboard route with the person's rights. | A copied token (credential store, stolen laptop) of an administrator who signed in to the app would administer the whole office. | `LimitAgentToken`: a token that carries only the `agent` ability reaches just what the app calls (`GET /me`, `POST /me/consent`, sync, screenshots, the person's own pictures). The dashboard's cookie sign-in is not affected. | `SecurityTest` (blocked routes, allowed routes) |
| 3 | The login answered faster for an address with no account (no password check ran). | Response time revealed which emails have an account. | A fixed hash is checked when the account does not exist, so both take as long. | reviewed (timing is not stable enough to test) |
| 4 | Every desktop login left a new token behind, and a very long `X-Device-Id` header was written into a column of 255 characters. | Tokens piled up (each valid 30 days); a long header could cause a server error. | Signing in again on a PC replaces that PC's token; the header is cut at 64 characters. | `SecurityTest` |
| 5 | No way to check that a server's settings are safe. `docs/SETUP.md` also lacked the secure-cookie setting, security headers, HSTS and an upload size that fits a screenshot (nginx's default 1 MB would refuse 1.5 MB pictures). | A server could go live with debug on, an http address, or a cookie that travels unencrypted. | `php artisan tracker:security-check` (fails on anything unsafe, warns about the rest) and the missing settings in SETUP sections 4, 6, 8 and 9. | `SecurityTest` |

## 2. Checked and found in order

| Area (§15) | What was checked | Result |
|---|---|---|
| Every route needs sign-in | All 76 routes listed and filtered by middleware (`php artisan route:list --json`). | Only `POST /api/v1/auth/login`, `GET /health` and the login, logout, forgot and reset routes of the dashboard are open. Every other route has `auth:sanctum`. |
| Permission on every admin route | The same list. | Every `admin/*`, `roles`, `reports/*` and `platform/*` route carries `permission:` or `platform:` middleware; the person routes carry `self-or-visible`. |
| Tenant isolation | `TenantIsolationTest`: the people of office B, including its admin, call every route with office A's ids. | 404 for every id of the other office and only own rows in every list. The organization scope is added to every model that has an `organization_id`. |
| Nobody gives more than they have | `RoleController`, `AdminEmployeeController`; `RoleManagementTest`, `RoleAssignmentTest`. | `ROLE_ESCALATION` on making, changing, giving and deleting a role that can do more than the caller's own. |
| Deactivated people | `EnsureActiveUser` checks the account on **every** request; deactivating also deletes the non-desktop tokens. | The next request after deactivation is refused. Test added for the token. The desktop sync route accepts a deactivated person's last data on purpose (§10.1) and tells the app to sign out. |
| Token lifetime | `config/sanctum.php`: `agent_token_days`, 7 days from the last use (`AGENT_TOKEN_DAYS` in `.env`). | Expired tokens are refused (test added). A password reset and a deactivation delete the tokens. |
| Passwords | Hashed with bcrypt (12 rounds in production); a reset needs a valid link, sets at least 10 characters and signs out everything; the answer to "forgot password" is the same for every address. | In order. |
| Injection | Every raw SQL fragment in `app/` (`whereRaw`, `selectRaw`) is parameterized or holds no input. No `{!!` and no `v-html` in the dashboard or the app. | In order. |
| Spreadsheet formulas | The report export prefixes cells that start with `=`, `+`, `-` or `@` (`ReportController::safe`). Application names come from people's PCs, so this matters. | In order. |
| Screenshots | Files sit on a private disk and reach a browser only through the API after the same reach check as a timeline; no path, bucket or link in any answer; the type is checked from the file itself, not its name; size and pixel limits. | In order (`ScreenshotTest`, `ScreenshotS3Test`). |
| Uploads and input | Every request goes through a form request or `validate`; the sync batch limits every number. | In order. |
| Rate limits | Sync 30 a minute, screenshots 60, import and invitations 10, password change 5, reset 5 and 10, logins as above. | In order after fix 1. |
| Errors and logs | `APP_DEBUG=false` gives `Something went wrong.` without a trace or a query (test added); no code writes to the log by hand; passwords and tokens are hidden from the model output. | In order. The check command refuses a server with debug on. |
| The token on the PC | The desktop app keeps its token in the Windows Credential Manager (`keyring`), not in a file. | In order. |
| Least privilege in the app | The app's window has only `core:default` permissions. | In order. |
| Dependencies | `composer audit`: no advisories. `pnpm audit --prod`: none. `cargo audit`: see section 4. | In order on 2026-09-26. Repeat before every release. |

## 3. Speed with many people (§14)

`ManyPeopleTest` fills an office with 3 and then with 60 people (five days each) and counts the database queries of the people list and of the three reports. The count does not grow with the number of people, so no page runs a query per person. It is an automatic test: a change that adds one fails it. What it does not tell is the time on a real server with a real database of months of data: that is measured in the pilot (`docs/PILOT_CHECKLIST.md`, section 7).

## 4. Dependency audit of the desktop app

`cargo audit` (cargo-audit 0.22.2, 1271 advisories, 612 crates in `Cargo.lock`): **no vulnerabilities**, and 7 warnings, all of crates that Tauri itself pulls in and that this project does not use directly:

- `proc-macro-error` (RUSTSEC-2024-0370) and five `unic-*` crates (RUSTSEC-2025-0075, -0080, -0081, -0098, -0100): no longer maintained. They run when the app is compiled or in Tauri's own URL handling, and there is no known flaw.
- `glib` 0.18.5 (RUSTSEC-2024-0429): an unsound iterator. `glib` belongs to the Linux window toolkit and is not part of the Windows build.

Nothing to do now. They go away when Tauri updates its own dependencies: run `cargo update` and `cargo audit` again before a release.

## 5. The weaknesses that were left open, and how they were closed

The first pass left four weaknesses open. They were closed afterwards, each with automatic tests:

| # | Weakness | What was done | Tests |
|---|---|---|---|
| A | The desktop window had no content security policy. | `tauri.conf.json` now sets one: only the app's own files, no scripts from strings (no `unsafe-eval`), no remote content, no frames, and the single address the window uses to talk to its Rust side (`ipc:` and `http://ipc.localhost`). Checked on the **built** app: it was started with WebView2's debugging port, and the login, main, settings and screenshot screens were opened while listening for violations and console errors; there were none, and pictures shown from data addresses load. | `csp_tests` (the policy cannot go back to `null`) |
| B | The desktop app's local database was not encrypted. | Window titles, application names, process names, the queue of what is waiting to be sent, the personal details kept in `app_state` and the pictures waiting on disk are encrypted with AES-256-GCM (a fresh random nonce per value). The key is made on first run and kept in Windows Credential Manager (`db-key`) next to the sign-in token, so a copy of the database file, a backup or another PC user's read of the disk shows only noise. A database from the older version is encrypted in place at the first start; the copy made before the upgrade (`tracker.db.bak`) is removed and the file compacted so old clear text does not linger. Ids, times and statuses stay readable so the queries work. If the key is lost (Windows profile reset) the old file is kept aside, a fresh one is started and the server is told (`dbReset`), like a corrupted file. Tried on this machine on a real database of 5,098 sessions: they were encrypted at the first start, the timeline still showed the names, and the encrypted waiting session was sent and accepted by the server. | `db::tests` (encrypted in the file and the log, round trip, old database upgraded, opened twice, key lost, pictures) and `db::crypt::tests`; the Credential Manager test is run by hand with `cargo test --lib key_store -- --ignored` |
| C | No second factor. | Optional two-factor sign-in for the dashboard with an authenticator app (6-digit code, RFC 6238) and 8 single-use recovery codes, turned on from the profile page with the password. The secret is stored encrypted and the recovery codes only as hashes; a code cannot be used twice; five wrong codes in 15 minutes stop the guessing for that person whatever the password step allows; the sign-in step is a short-lived challenge, so nothing is signed in before the code. Someone who lost their phone is reset by a manager with `people.update` in reach, by a superadmin, or on the server with `php artisan tracker:reset-two-factor <email>` (the owner's way back); every reset is audited and signs the person out. The desktop login is not affected (its token is limited to the app). It is optional per person; requiring it for chosen roles can follow the pilot. | `TwoFactorTest` (14 tests) and `21-two-factor.spec.ts` |
| D | No list of signed-in PCs, and a 30-day desktop token. | A person sees the PCs where they are signed in to the desktop app on their profile page and can sign one out; a manager can do the same for someone in reach. A desktop token now lasts 7 days from its last **use** (`AGENT_TOKEN_DAYS`); every use after the first day pushes it forward, so an app used daily never asks again and an unused PC stops working within a week. | `DeviceTest`, `SecurityTest`, `20-devices.spec.ts` |

Still open, and accepted:

| # | What | Why | What to do |
|---|---|---|---|
| E | A key kept in Credential Manager protects the data from a copy of the file, not from someone who is signed in to Windows as that person and runs code as them. | That person could also read what the app shows. Encrypting against the signed-in user needs a hardware key or a password on the app, which people would resent. | BitLocker covers a stolen PC; the account of a departed person is deactivated and their PC signed out. |
| F | Hardware and driver-level input cannot be told from a real keyboard (activity check). | Physics of the check, documented in §16 of the plan. | none |
| G | Two-factor is optional. | Turning it on for everyone before the pilot would lock people out while the reset routes are still new. | Decide with the offices after the pilot; a rule "required for roles that can manage or see others" is a small addition. |
| H | Hardware security keys (WebAuthn) as a second factor. | Not asked for; authenticator apps cover the need. | Later, if an office requires it. |

## 6. Not done, and only you (or a specialist) can do

- **The real server:** firewall, MySQL listening only on localhost, https and HSTS, key-only SSH, system updates, file permissions. `docs/SETUP.md` sections 3, 6, 8 and the check command are the recipe; run `php artisan tracker:security-check` there and `nmap` from outside (`docs/PILOT_CHECKLIST.md`, section 2).
- **A professional penetration test** or at least an outside review of the sign-in, roles and tenant separation, before many offices are on one server.
- **Legal and privacy review** per office: the notice text, the retention period, who may see what, and the agreement with each office.
- **Code signing** of the installer (SmartScreen warning) and the upgrade-in-place check.
