<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/v1/admin/audit?cursor= (docs/DEVELOPMENT_PLAN.md §9.1, §10). Carries the
// `oic` middleware — the audit log spans the whole hierarchy (§10 Test 2.14), not just
// the caller's own branch, so it's OIC-only rather than hierarchy-scoped.
class AdminAuditController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with(['actor', 'target'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

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
