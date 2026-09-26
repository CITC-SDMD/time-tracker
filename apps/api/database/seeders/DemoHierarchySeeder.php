<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Role;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\OrganizationService;
use App\Support\OrganizationContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// two small offices for trying the dashboard by hand and for the live tests of docs/DEVELOPMENT_PLAN.md
// phases 6 and 11. The demo office (made by UserSeeder) has the OIC, 2 project managers, 3 team leaders and 6 members with
// roles it made itself, all with
// the password "password", 60 days of daily totals and a mix of live states (tracking, idle, paused,
// not tracking, offline) with a few timelines for today. never runs in production. run it after
// `php artisan migrate:fresh --seed` with: php artisan db:seed --class=DemoHierarchySeeder
// running it again is safe: every person is found by email, and the sessions and totals it writes replace the old ones.
class DemoHierarchySeeder extends Seeder
{
    private const APPS = [
        'code' => 'Visual Studio Code',
        'chrome' => 'Google Chrome',
        'slack' => 'Slack',
        'excel' => 'Microsoft Excel',
        'figma' => 'Figma',
    ];

    private Organization $org;

    /** @var array<string, Role> the roles of the office being seeded, by name */
    private array $roles = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        mt_srand(2026); // the same offices every time
        $context = app(OrganizationContext::class);

        $this->org = Organization::where('slug', 'demo-office')->firstOrFail(); // made by UserSeeder
        $context->within($this->org->id, fn () => $this->seedDemoOffice());

        // a second office, so the isolation between offices can be tried: its own admin, roles and two people
        $this->org = Organization::where('slug', 'other-office')->first() ?? app(OrganizationService::class)->create('Other Office');
        $context->within($this->org->id, fn () => $this->seedOtherOffice());
    }

    private function seedOtherOffice(): void
    {
        $admin = Role::where('is_system', true)->firstOrFail();
        $admin->name = 'Admin';
        $admin->save();
        $this->roles = ['Admin' => $admin];
        $this->role('Lead', 'team', ['people.view', 'people.create', 'people.update', 'timeline.view', 'screenshots.view', 'reports.view', 'reports.export']);
        $this->role('Staff', 'self', []);

        $boss = $this->person('Bea Admin', 'admin.b@test.com', 'Admin', null);
        $lead = $this->person('Lena Lead', 'lead.b@test.com', 'Lead', $boss);
        $staff = [
            $this->person('Ben Staff', 'b1@test.com', 'Staff', $lead),
            $this->person('Bianca Staff', 'b2@test.com', 'Staff', $lead),
        ];
        $timezone = OrganizationSetting::current()->timezone;
        foreach ([$lead, ...$staff] as $user) {
            for ($back = 1; $back <= 5; $back++) {
                $day = now($timezone)->subDays($back);
                if ($day->isWeekend()) {
                    continue;
                }
                $tracked = mt_rand(4 * 3600, 8 * 3600);
                $active = (int) ($tracked * 0.85);
                $this->upsertSummary($user, $day->format('Y-m-d'), $tracked, $active, $tracked - $active, $this->split($active),
                    $day->copy()->setTime(9, 0)->utc(), $day->copy()->setTime(9, 0)->addSeconds($tracked)->utc());
            }
        }
        $this->live($staff[0], 'active', 'Google Chrome', 1);
        $this->todaysSessions($staff[0], $timezone);
    }

    private function seedDemoOffice(): void
    {
        $timezone = OrganizationSetting::current()->timezone;
        $this->roles = ['OIC' => Role::where('is_system', true)->firstOrFail()];
        $manager = ['people.view', 'people.create', 'people.update', 'people.assign_role', 'timeline.view', 'screenshots.view', 'reports.view', 'reports.export'];
        $this->role('Project Manager', 'team', $manager);
        $this->role('Team Leader', 'team', $manager);
        foreach (['Lead Developer', 'Developer', 'Client Support', 'QA', 'System Analyst'] as $name) {
            $this->role($name, 'self', []);
        }

        $oic = $this->person('OIC', 'oic@test.com', 'OIC', null);
        $pm1 = $this->person('Paula Reyes', 'pm1@test.com', 'Project Manager', $oic);
        $pm2 = $this->person('Pedro Santos', 'pm2@test.com', 'Project Manager', $oic);
        $tl1 = $this->person('Tina Cruz', 'tl1@test.com', 'Team Leader', $pm1);
        $tl2 = $this->person('Tomas Diaz', 'tl2@test.com', 'Team Leader', $pm1);
        $tl3 = $this->person('Tess Lim', 'tl3@test.com', 'Team Leader', $pm2);
        $members = [
            $this->person('Dan Ramos', 'dev1@test.com', 'Lead Developer', $tl1),
            $this->person('Dana Uy', 'dev2@test.com', 'Developer', $tl1),
            $this->person('Dex Tan', 'dev3@test.com', 'Developer', $tl2),
            $this->person('Quinn Go', 'qa1@test.com', 'QA', $tl2),
            $this->person('Cara Sy', 'cs1@test.com', 'Client Support', $tl3),
            $this->person('Sam Ong', 'sa1@test.com', 'System Analyst', $tl3),
        ];

        // sixty days of totals for everyone below the OIC, weekdays only
        foreach ([$pm1, $pm2, $tl1, $tl2, $tl3, ...$members] as $user) {
            for ($back = 1; $back <= 60; $back++) {
                $day = now($timezone)->subDays($back);
                if ($day->isWeekend() || mt_rand(1, 10) === 1) {
                    continue;
                }
                $tracked = mt_rand(4 * 3600, 9 * 3600);
                $active = (int) ($tracked * mt_rand(78, 95) / 100);
                $apps = $this->split($active);
                $this->upsertSummary($user, $day->format('Y-m-d'), $tracked, $active, $tracked - $active, $apps,
                    $day->copy()->setTime(8, mt_rand(0, 59))->utc(), $day->copy()->setTime(8, 0)->addSeconds($tracked + 3600)->utc());
            }
        }

        // today: a mix of live states, and real timelines for the first three members and Tina
        [$dev1, $dev2, $dev3, $qa1, $cs1] = $members;
        $this->live($dev1, 'active', 'Visual Studio Code', 1);
        $this->live($dev2, 'idle', null, 2, 'Microsoft Excel');
        $this->live($dev3, 'paused', null, 3);
        $this->live($qa1, 'not_tracking', null, 4);
        $this->live($cs1, 'active', 'Slack', 25); // last synced 25 minutes ago: shows as offline
        $this->live($tl1, 'active', 'Google Chrome', 1);
        foreach ([$dev1, $dev2, $tl1] as $user) {
            $this->todaysSessions($user, $timezone);
        }
        // a few pictures for the gallery (screenshots stay switched off in the office settings)
        foreach ([$dev1, $dev2] as $user) {
            $this->screenshots($user);
        }
    }

    /** six small generated pictures over the last hour, written through the real model so the files and thumbnails exist */
    private function screenshots(User $user): void
    {
        if (! function_exists('imagejpeg')) {
            return; // the image extension is missing: no pictures, the rest of the demo still works
        }

        Screenshot::where('user_id', $user->id)->get()->each->delete(); // deleting also removes the files

        foreach (range(1, 6) as $i) {
            $shot = Screenshot::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'taken_at' => now()->subMinutes($i * 10),
                'width' => 640,
                'height' => 360,
            ]);
            $path = tempnam(sys_get_temp_dir(), 'demo');
            $image = imagecreatetruecolor(640, 360);
            imagefilledrectangle($image, 0, 0, 640, 360, imagecolorallocate($image, 30 + $i * 25, 60, 200 - $i * 20));
            imagestring($image, 5, 20, 20, "demo screenshot {$i} of {$user->name}", imagecolorallocate($image, 255, 255, 255));
            imagejpeg($image, $path, 70);
            $shot->addMedia($path)->usingFileName($shot->id.'.jpg')->toMediaCollection(Screenshot::COLLECTION);
        }
    }

    /** a role this office made for itself (or the one it already has under that name) */
    private function role(string $name, string $scope, array $permissions): Role
    {
        return $this->roles[$name] ??= Role::unguarded(fn () => Role::firstOrCreate(
            ['name' => $name],
            ['scope' => $scope, 'permissions' => $permissions, 'is_system' => false],
        ));
    }

    private function person(string $name, string $email, string $roleName, ?User $manager): User
    {
        return User::unguarded(fn () => User::withoutGlobalScopes()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make('password'),
            'organization_id' => $this->org->id,
            'role_id' => $this->roles[$roleName]->id,
            'manager_id' => $manager?->id,
            'status' => 'active',
        ]));
    }

    /** @return array<string, int> active seconds per app key, adding up to $seconds */
    private function split(int $seconds): array
    {
        $keys = array_keys(self::APPS);
        $weights = array_map(fn () => mt_rand(1, 10), $keys);
        $apps = [];
        foreach ($keys as $i => $key) {
            $apps[$key] = intdiv($seconds * $weights[$i], array_sum($weights));
        }
        $apps[$keys[0]] += $seconds - array_sum($apps);

        return $apps;
    }

    /** @param array<string, int> $apps */
    private function upsertSummary(User $user, string $day, int $tracked, int $active, int $idle, array $apps, $first, $last): void
    {
        DB::table('daily_summaries')->updateOrInsert(['user_id' => $user->id, 'day' => $day], [
            'organization_id' => $this->org->id,
            'tracked_seconds' => $tracked,
            'active_seconds' => $active,
            'idle_seconds' => $idle,
            'apps' => json_encode($apps),
            'app_names' => json_encode(array_intersect_key(self::APPS, $apps)),
            'first_activity_at' => $first,
            'last_activity_at' => $last,
        ]);
    }

    private function live(User $user, string $state, ?string $app, int $minutesAgo, ?string $idleApp = null): void
    {
        DB::table('employee_statuses')->updateOrInsert(['user_id' => $user->id], [
            'organization_id' => $this->org->id,
            'state' => $state,
            'current_app' => $app,
            'idle_app_name' => $idleApp,
            'since' => now()->subMinutes($minutesAgo + 10),
            'last_seen_at' => now()->subMinutes($minutesAgo),
            'clock_skew_seconds' => 0,
        ]);
    }

    /** Four hours of alternating app and idle sessions ending a few minutes ago; today's totals follow from them. */
    private function todaysSessions(User $user, string $timezone): void
    {
        DB::table('sessions')->where('user_id', $user->id)->where('started_at', '>=', now()->subDay())->delete();

        $device = (string) Str::uuid();
        $cursor = now()->subMinutes(5);
        $keys = array_keys(self::APPS);
        $totals = []; // day => [tracked, active, idle, apps, first, last]

        for ($block = 0; $block < 10 && $cursor->diffInMinutes(now()) < 240; $block++) {
            $idle = $block % 4 === 3;
            $seconds = $idle ? mt_rand(300, 600) : mt_rand(900, 2400);
            $start = $cursor->copy()->subSeconds($seconds);
            $key = $keys[mt_rand(0, count($keys) - 1)];
            $day = $start->copy()->setTimezone($timezone)->format('Y-m-d');

            DB::table('sessions')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $this->org->id,
                'user_id' => $user->id,
                'device_id' => $device,
                'type' => $idle ? 'idle' : 'application',
                'app_name' => $idle ? null : self::APPS[$key],
                'app_key' => $idle ? null : $key,
                'process_name' => $idle ? null : $key.'.exe',
                'window_title' => $idle ? null : self::APPS[$key].' - demo',
                'idle_app_name' => $idle ? self::APPS[$key] : null,
                'started_at' => $start,
                'ended_at' => $cursor,
                'duration_seconds' => $seconds,
                'day' => $day,
                'clock_changed' => false,
                'received_at' => now(),
            ]);

            $t = &$totals[$day];
            $t ??= ['tracked' => 0, 'active' => 0, 'idle' => 0, 'apps' => [], 'first' => $start, 'last' => $cursor];
            $t['tracked'] += $seconds;
            $t[$idle ? 'idle' : 'active'] += $seconds;
            if (! $idle) {
                $t['apps'][$key] = ($t['apps'][$key] ?? 0) + $seconds;
            }
            $t['first'] = $start; // walking backwards in time
            unset($t);

            $cursor = $start->copy()->subMinutes(mt_rand(1, 5));
        }

        foreach ($totals as $day => $t) {
            $this->upsertSummary($user, $day, $t['tracked'], $t['active'], $t['idle'], $t['apps'], $t['first'], $t['last']);
        }
    }
}
