# Browser tests (Playwright + Chromium)

End-to-end tests of the dashboard as an OIC, project manager and team leader: sign in, reset
password, overview, a person's day, people (create / read / update / delete), reports and CSV,
office settings, audit log, profile, light and dark mode, phone layout, accessibility, security.

Every test fails on a console error or warning, an uncaught exception, or an unexpected 4xx/5xx.

## One-time setup

1. A separate database, so `tracker_dev` is never touched (MySQL, as an account that may grant):

   ```sql
   CREATE DATABASE tracker_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON tracker_e2e.* TO 'tracker'@'localhost';
   ```

2. `apps/api/.env.e2e` (git-ignored). Copy `.env`, then set `APP_ENV=e2e`,
   `APP_URL=http://localhost:8001`, `DASHBOARD_URL=http://localhost:3101`,
   `SANCTUM_STATEFUL_DOMAINS=localhost:3101`, `DB_DATABASE=tracker_e2e`,
   `MAIL_MAILER=log` (mail is read back from `storage/logs/laravel.log`, nothing is sent),
   `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `LOG_CHANNEL=single`.

3. `pnpm install`, then `pnpm --filter e2e exec playwright install chromium`.

## Run

```
pnpm e2e                          # everything, headless
pnpm --filter e2e test:headed     # watch it
pnpm --filter e2e test:ui         # Playwright's UI mode
pnpm --filter e2e report          # open the HTML report of the last run
```

The run starts its own API on port 8001 and the built dashboard on 3101 (so it can run next to
`pnpm dev:dashboard`), rebuilds the database with `migrate:fresh --seed` plus the demo office, and
signs each demo account in once (`.auth/`). The spec files are numbered because later ones read
what earlier ones did (for example the audit log); run the whole suite, not single files, when a
test asks for that data.
