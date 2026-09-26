<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

// Reads this server's own settings and says which ones are unsafe for a real installation (docs/SECURITY_REVIEW.md).
// It looks only at configuration, never at data, and changes nothing. Run it on the server after every change to `.env`
// and before the first person signs in: it exits with an error when something must be fixed.
class SecurityCheck extends Command
{
    protected $signature = 'tracker:security-check';

    protected $description = 'Check this server\'s settings for anything unsafe in production';

    public function handle(): int
    {
        $problems = [];
        $warnings = [];

        if (config('app.env') !== 'production') {
            $problems[] = 'APP_ENV is "'.config('app.env').'", it must be "production".';
        }
        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG is on: an error page would show code, queries and settings. Set APP_DEBUG=false.';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL is not an https address: passwords and tokens would cross the network unencrypted.';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE is not true: the sign-in cookie could be sent over plain http.';
        }
        if (! config('session.http_only')) {
            $problems[] = 'SESSION_HTTP_ONLY is off: scripts in the page could read the sign-in cookie.';
        }
        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $problems[] = 'SESSION_SAME_SITE must be lax or strict.';
        }
        if (config('queue.default') === 'sync') {
            $problems[] = 'QUEUE_CONNECTION is "sync": invitation emails and thumbnails would slow every request. Use "database" and run the queue worker.';
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $problems[] = 'MAIL_MAILER is "'.config('mail.default').'": no email would ever be sent.';
        }
        if (config('database.connections.'.config('database.default').'.username') === 'root') {
            $problems[] = 'The database user is "root": give the app its own user that can reach only its own database.';
        }
        foreach ((array) config('sanctum.stateful') as $domain) {
            if (preg_match('/^(localhost|127\.|0\.0\.0\.0)/', trim($domain))) {
                $problems[] = 'SANCTUM_STATEFUL_DOMAINS still lists "'.trim($domain).'": list only the real dashboard address.';
            }
        }
        if (! config('sanctum.expiration')) {
            $problems[] = 'Desktop sign-ins never expire (SANCTUM_TOKEN_EXPIRATION_MINUTES is empty).';
        }

        if (config('logging.channels.single.level', 'debug') === 'debug') {
            $warnings[] = 'LOG_LEVEL is "debug": the log grows fast. "warning" is enough in production.';
        }
        if (config('filesystems.disks.screenshots.driver') === 'local') {
            $warnings[] = 'Screenshots are stored on this server\'s own disk. Use SCREENSHOT_DISK=s3 on the storage server for a real installation.';
        }
        if (config('app.timezone') !== 'UTC') {
            $warnings[] = 'APP_TIMEZONE is "'.config('app.timezone').'": times are stored in UTC and each organization has its own timezone, so leave this UTC.';
        }

        foreach ($problems as $line) {
            $this->error('FIX  '.$line);
        }
        foreach ($warnings as $line) {
            $this->warn('NOTE '.$line);
        }
        if ($problems === []) {
            $this->info($warnings === [] ? 'Everything checked is fine.' : 'Nothing that must be fixed.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
