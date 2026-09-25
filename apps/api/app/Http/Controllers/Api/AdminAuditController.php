<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OfficeSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/v1/admin/audit?cursor=&action=&q=&from=&to= (docs/DEVELOPMENT_PLAN.md §9.1, §10). Carries the
// `oic` middleware — the audit log spans the whole hierarchy (§10 Test 2.14), not just
// the caller's own branch, so it's OIC-only rather than hierarchy-scoped.
class AdminAuditController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = AuditLog::with(['actor', 'target'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        // Matches the name of whoever did it or whoever it was done to (the names are copied into
        // each entry, so this still finds people whose account was deleted).
        if ($request->filled('q')) {
            $needle = '%'.addcslashes($request->string('q')->toString(), '%_\\').'%';
            $query->where(fn ($q) => $q->where('actor_name', 'like', $needle)->orWhere('target_name', 'like', $needle));
        }

        // Days are office-timezone days, like everything else on the dashboard.
        $timezone = OfficeSetting::current()->timezone;
        if ($request->filled('from')) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $request->string('from')->toString(), $timezone)->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $request->string('to')->toString(), $timezone)->addDay()->startOfDay()->utc());
        }

        // Cursor is the id of the last entry the caller already has — since entries
        // are immutable and ordered newest-first, "id < cursor" is enough on its own
        // (no need to also compare created_at) even though the ordering is timestamp-led.
        if ($cursor = $request->integer('cursor')) {
            $query->where('id', '<', $cursor);
        }

        $entries = $query->limit(self::PER_PAGE + 1)->get();
        $hasMore = $entries->count() > self::PER_PAGE;
        $entries = $entries->take(self::PER_PAGE);

        return response()->json([
            'entries' => $entries->map(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'actorName' => $entry->actor?->name ?? $entry->actor_name ?? 'Unknown',
                'action' => $entry->action,
                'targetName' => $entry->target?->name ?? $entry->target_name,
                'details' => $entry->details,
                'at' => $entry->created_at->toIso8601String(),
            ]),
            'nextCursor' => $hasMore ? $entries->last()->id : null,
        ]);
    }
}
