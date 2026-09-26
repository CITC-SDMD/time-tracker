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
| Token lifetime | `config/sanctum.php`: 30 days, changeable in `.env`. | Expired tokens are refused (test added). A password reset and a deactivation delete the tokens. |
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

## 5. Known and accepted, or open

| # | What | Why it is open | What to do |
|---|---|---|---|
| A | The desktop app's window has **no content security policy** (`"csp": null` in `tauri.conf.json`). | It only shows pages bundled with the app, never a remote page, and has minimal permissions, so the risk is low. A wrong policy breaks the window, and it can only be checked by running the built app. | Set one in the pilot build and check every screen. |
| B | The desktop app's local database (waiting data, window titles and application names) is **not encrypted**. | The token is protected, the data is what the office has agreed to send anyway, and Windows account separation protects it from other users of the PC. | Decide with the offices; disk encryption (BitLocker) covers a stolen laptop. |
| C | No second factor (2FA) for administrators and superadmins. | Not in the plan. The login limits and the 10-character minimum are the protection for now. | Consider before offices with sensitive data join. |
| D | The 30-day desktop token lifetime, and no list of signed-in PCs for a person to revoke one. | A stolen PC is handled by deactivating the account. | Add "sign out this PC" if offices ask. |
| E | An administrator who is deactivated keeps their dashboard **cookie** session until it expires (`SESSION_LIFETIME`, 120 minutes idle) unless the next request is checked. | It is checked on every request (`EnsureActiveUser`), so it ends at once. Listed only because the plan asks. | none |
| F | Hardware and driver-level input cannot be told from a real keyboard (activity check). | Physics of the check, documented in §16 of the plan. | none |

## 6. Not done, and only you (or a specialist) can do

- **The real server:** firewall, MySQL listening only on localhost, https and HSTS, key-only SSH, system updates, file permissions. `docs/SETUP.md` sections 3, 6, 8 and the check command are the recipe; run `php artisan tracker:security-check` there and `nmap` from outside (`docs/PILOT_CHECKLIST.md`, section 2).
- **A professional penetration test** or at least an outside review of the sign-in, roles and tenant separation, before many offices are on one server.
- **Legal and privacy review** per office: the notice text, the retention period, who may see what, and the agreement with each office.
- **Code signing** of the installer (SmartScreen warning) and the upgrade-in-place check.
