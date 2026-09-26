# Releasing the time tracker

There is no CI and no automatic updater (Phase 8 is out of scope), so a release is a short list done by hand. A release has up to three independent parts: the **server** (API), the **dashboard**, and the **desktop app** installer. Most releases touch only the first two; the desktop app is rebuilt and handed out only when it changed. Server installation itself is in `docs/SETUP.md`; this page is what to do every time after that.

Nothing is pushed, tagged or deployed without the owner saying so.

## 1. Before any release

Everything below must be green on the commit you are releasing. Run it from a clean working tree (`git status` shows nothing).

| Check | Command | Where |
|---|---|---|
| API tests and style | `php artisan test` and `vendor/bin/pint --test` | `apps/api` |
| Dashboard and agent front end | `pnpm lint` and `pnpm typecheck` | repository root |
| Desktop app engine | `cargo test --lib` and `cargo clippy --all-targets` | `apps/agent/src-tauri` |
| Whole browser suite | `pnpm e2e` | repository root (about 20 minutes; run it once, whole, not just the specs you touched) |
| Windows input test (only if the agent changed) | `cargo test --lib live_input -- --ignored --nocapture` | `apps/agent/src-tauri`, on an interactive desktop |
| Live checks for what changed | the relevant part of `docs/LIVE_TEST_ACTIVITY_CHECK.md` and the phase tests in `docs/DEVELOPMENT_PLAN.md` | real PC with the real server |

Then read the list of commits since the last release (`git log <last-tag>..HEAD --oneline`) and write the release notes (section 8). If anything changes what the desktop app collects or shows in "What we track", read section 6 before releasing.

## 2. Version numbers

- **Desktop app:** the version is in two places that must always be equal: `version` in `apps/agent/src-tauri/Cargo.toml` (this is the value the app sends as `X-Agent-Version`) and `version` in `apps/agent/src-tauri/tauri.conf.json` (the number the installer shows). Bump both, then run `cargo check` so `Cargo.lock` follows, and commit.
- **Server and dashboard** have no version number of their own: they are identified by the git commit (`git rev-parse --short HEAD`), which you write in the release notes.
- **The oldest desktop app the server accepts** is `min_agent_version` in Platform settings (dashboard, superadmin). Apps below it get "Please update the app" and keep tracking locally, but stop syncing. Raise it **only after every PC runs the new version**, never at the same time as the release, and never past a version that some PCs still lack. It is the only lever for forcing an update, and it locks people out of syncing if it is set too high.

## 3. Releasing the server and dashboard

Do this at a quiet time. All commands on the server, in `/var/www/time-tracker` (adjust to your path).

1. **Back up the database first.** A migration cannot always be undone cleanly, so the backup is the rollback.
   ```bash
   mysqldump -u tracker -p tracker_prod | gzip > ~/backup-before-release-$(date +%F-%H%M).sql.gz
   ```
   Check the file is not empty. Also note the current commit: `git rev-parse HEAD`.
2. **Get the code** (the tag or commit you tested):
   ```bash
   git fetch && git checkout <tag-or-commit>
   ```
3. **API:**
   ```bash
   cd apps/api
   composer install --no-dev --optimize-autoloader
   php artisan down --retry=60          # optional: shows a maintenance page while migrating
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan up
   ```
4. **Restart what holds old code in memory.** The queue worker sends invitation emails, makes thumbnails and recalculates days after a timezone change, so it must run the new code:
   ```bash
   sudo systemctl restart tracker-queue
   sudo systemctl reload php8.3-fpm        # your PHP-FPM service name; clears the opcode cache
   ```
5. **Dashboard:** build and copy the static files (SETUP §5):
   ```bash
   cd ../.. && pnpm install && pnpm --filter dashboard generate
   sudo rsync -a --delete apps/dashboard/.output/public/ /var/www/tracker-dashboard/
   ```
6. **Run the checks in section 5.**

A release that changes the desktop app's data (new fields in `POST /agent/sync`) is safe to deploy to the server first: the server accepts the old and the new app, and ignores fields it does not know.

## 4. Building and handing out the desktop app

Only when the app changed. On a Windows machine with the tools from SETUP ("Desktop app on Windows"):

1. Bump the version (section 2) and commit.
2. Point the build at the real server, then build:
   ```powershell
   $env:TRACKER_API_URL = "https://tracker.yourcompany.com"
   # only if the dashboard has its own address:
   # $env:TRACKER_DASHBOARD_URL = "https://dashboard.yourcompany.com"
   pnpm build:agent
   ```
   The installer is `apps/agent/src-tauri/target/release/bundle/nsis/Time Tracker_<version>_x64-setup.exe`. Building without `TRACKER_API_URL` produces an app that talks to `http://127.0.0.1:8000`: never hand that one out.
3. **Test the installer on a clean Windows PC before anyone else gets it:** install, sign in, accept the notice, track for ten minutes, check the data on the dashboard, quit from the tray, start again from the desktop shortcut. Then install the **new** installer over an **old** one and check that the person's waiting data and settings survive the upgrade. (This upgrade path has not been verified yet: do it before the first real rollout.)
4. Hand the file out by any secure route (a shared drive, a download link on the office server). The installer is not code-signed yet, so Windows SmartScreen shows "Windows protected your PC": people choose "More info" then "Run anyway". Buying a code-signing certificate removes the warning and is worth doing before more than a few offices use the app.
5. Keep the previous installer file: it is the rollback for the desktop app.

There is no automatic update: each person runs the new installer. After everyone has, you may raise `min_agent_version` (section 2).

## 5. After a release: check it works

On the server:
- `curl -s https://tracker.yourcompany.com/health` answers `{"ok":true,...}` (it only proves the app is up, not that the database works: the browser check below covers that).
- `sudo systemctl status tracker-queue` is active; `php artisan queue:failed` is empty (or explain what is in it).
- `tail -n 50 apps/api/storage/logs/laravel.log` shows no new errors.

In a browser: sign in as an admin of a real organization, open Overview and a person's day, and check today's numbers are moving. Send a test invitation (add a person and delete them, or use "Resend link") and confirm the email arrives (SMTP settings are in `.env`).

On one real PC with the new app: sign in, start tracking, and check "All data sent" in the app and the same time on the dashboard.

Keep an eye on the log and the queue for the first day.

## 6. When the tracking notice changes

Anything new the desktop app reads or reports (for example the virtual machine check or the activity check) changes the "What we track" text, and people must accept it again:

1. Release the desktop app with the new text (section 4).
2. In each organization, an admin raises the **consent version** in Settings. The desktop apps ask each person to accept the new notice before they track again. Superadmins with the permission can do it while looking inside an office.
3. Tell the organizations' admins what changed in plain words before they raise it (use the release notes).

Raising the version without the new app installed would show people the old text: release the app first.

## 7. Rolling back

- **Server code only (no migration ran, or the new migration is harmless):** `git checkout <previous-commit>`, redo section 3 steps 3 to 5 (skip `migrate`), restart the queue and PHP-FPM.
- **A migration ran and it must be undone:** put the site in maintenance (`php artisan down`), restore the backup taken in section 3 step 1 (`gunzip < backup.sql.gz | mysql -u tracker -p tracker_prod`), check out the previous commit, redo steps 3 to 5, then `php artisan up`. Anything recorded between the release and the rollback is lost with the restore, so do this early. Test a restore on a copy of the database before you ever need it.
- **Desktop app:** hand out the previous installer over the new one. Do not lower `min_agent_version` below what the older app needs, and do not raise it while rolling back.

## 8. Release notes and tag

Write a short note for each release and keep it with the tag: what changed for **admins**, what changed for **people using the desktop app**, anything they must do (raise the consent version, install the new app), the commit, the desktop app version, and the migrations that ran. Tag only when told to:

```bash
git tag -a v0.2.0 -m "Release 0.2.0"    # then, when asked: git push origin v0.2.0
```

## 9. Known gaps (not done, on purpose or not yet)

- No automatic updates, no CI, no code signing (Phase 8 is out of scope; a certificate should be ordered early).
- The upgrade-in-place of the desktop app is not verified yet (section 4 step 3).
- The Windows raw input test needs an interactive desktop, so it cannot run unattended.
- The activity check's thresholds are a first guess until real days have been looked at (`docs/LIVE_TEST_ACTIVITY_CHECK.md`).
