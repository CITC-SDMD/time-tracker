<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Services\AccessService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// GET /api/v1/reports/daily, /reports/apps and /reports/team (docs/DEVELOPMENT_PLAN.md §12 Phase 11).
// Behind `permission:reports.view` and always limited to the people the caller can see (§9.1): a role that reaches
// the organization gets everyone, one that reaches a team gets that team. `format=csv` downloads the same rows as a
// file and needs `reports.export`.
class ReportController extends Controller
{
    public function __construct(
        private AccessService $access,
        private ReportService $reports,
    ) {}

    public function daily(Request $request): JsonResponse|StreamedResponse
    {
        return $this->respond($request, 'daily', fn (array $ids, string $from, string $to) => $this->reports->daily($ids, $from, $to));
    }

    public function apps(Request $request): JsonResponse|StreamedResponse
    {
        return $this->respond($request, 'apps', fn (array $ids, string $from, string $to) => $this->reports->apps($ids, $from, $to));
    }

    public function team(Request $request): JsonResponse|StreamedResponse
    {
        return $this->respond($request, 'team', fn (array $ids, string $from, string $to) => $this->reports->team($ids, $from, $to));
    }

    /** @param  callable(list<int>, string, string): list<array<string, mixed>>  $build */
    private function respond(Request $request, string $report, callable $build): JsonResponse|StreamedResponse
    {
        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'uid' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $days = CarbonImmutable::createFromFormat('Y-m-d', $from)->diffInDays(CarbonImmutable::createFromFormat('Y-m-d', $to)) + 1;
        if ($days > ReportService::MAX_DAYS) {
            return response()->json([
                'error' => ['code' => 'RANGE_TOO_LONG', 'message' => 'Ask for at most '.ReportService::MAX_DAYS.' days at a time.'],
            ], 422);
        }

        $caller = $request->user();
        $uid = $request->filled('uid') ? $request->integer('uid') : null;
        if ($uid !== null && ! User::whereKey($uid)->exists()) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']], 404);
        }
        if ($uid !== null && ! $this->access->isVisible($caller, $uid)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'This person is not in your reach.'],
            ], 403);
        }

        $csv = $request->string('format')->toString() === 'csv';
        if ($csv && ! $this->access->can($caller, 'reports.export')) {
            return response()->json([
                'error' => ['code' => 'PERMISSION_DENIED', 'message' => 'Your role does not allow downloading reports.'],
            ], 403);
        }

        $rows = $build($uid !== null ? [$uid] : $this->access->visibleUserIds($caller), $from, $to);

        if (! $csv) {
            return response()->json($rows);
        }

        // a superadmin looking inside an organization is not logged
        if (! $caller->isSuperadmin()) {
            AuditLog::record($caller, 'report.exported', $uid !== null ? User::find($uid) : null, ['report' => $report, 'from' => $from, 'to' => $to]);
        }

        return $this->csv($report, $rows, $from, $to);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function csv(string $report, array $rows, string $from, string $to): StreamedResponse
    {
        $timezone = OrganizationSetting::current()->timezone;
        [$header, $line] = match ($report) {
            'daily' => [
                ['Date', 'Name', 'Email', 'Role', 'Tracked (HH:MM)', 'Tracked (seconds)', 'Active (HH:MM)', 'Active (seconds)', 'Idle (HH:MM)', 'Idle (seconds)', 'First activity', 'Last activity'],
                fn (array $r) => [
                    $r['day'], $r['name'], $r['email'], $r['role'],
                    $this->hhmm($r['trackedSeconds']), $r['trackedSeconds'],
                    $this->hhmm($r['activeSeconds']), $r['activeSeconds'],
                    $this->hhmm($r['idleSeconds']), $r['idleSeconds'],
                    $this->clock($r['firstActivityAt'], $timezone), $this->clock($r['lastActivityAt'], $timezone),
                ],
            ],
            'apps' => [
                ['Application', 'Active (HH:MM)', 'Active (seconds)', 'People'],
                fn (array $r) => [$r['app'], $this->hhmm($r['seconds']), $r['seconds'], $r['people']],
            ],
            default => [
                ['Name', 'Email', 'Role', 'Manager', 'Days tracked', 'Tracked (HH:MM)', 'Tracked (seconds)', 'Active (HH:MM)', 'Active (seconds)', 'Idle (HH:MM)', 'Idle (seconds)', 'Average per day (HH:MM)'],
                fn (array $r) => [
                    $r['name'], $r['email'], $r['role'], $r['managerName'] ?? '', $r['daysTracked'],
                    $this->hhmm($r['trackedSeconds']), $r['trackedSeconds'],
                    $this->hhmm($r['activeSeconds']), $r['activeSeconds'],
                    $this->hhmm($r['idleSeconds']), $r['idleSeconds'],
                    $this->hhmm($r['averageTrackedSeconds']),
                ],
            ],
        };

        return response()->streamDownload(function () use ($header, $line, $rows) {
            $out = fopen('php://output', 'w');
            // the byte order mark makes Excel read the file as UTF-8 (names with accents)
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map($this->safe(...), $line($row)));
            }
            fclose($out);
        }, "{$report}-report-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function hhmm(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private function clock(?string $iso, string $timezone): string
    {
        return $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone($timezone)->format('H:i');
    }

    /**
     * A name or app title that starts with = + - or @ would be run as a formula when the file is
     * opened in Excel, so it gets a leading apostrophe. Numbers are left alone.
     */
    private function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
