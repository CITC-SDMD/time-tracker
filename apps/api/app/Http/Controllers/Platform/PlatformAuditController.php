<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Api\AdminAuditController;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

// GET /api/v1/platform/audit (docs/DEVELOPMENT_PLAN.md §9.4): everything superadmins did, in any organization or
// on the platform itself. Same filters and paging as an organization's log; needs `platform.audit.view`.
class PlatformAuditController extends AdminAuditController
{
    protected function baseQuery(): Builder
    {
        return AuditLog::withoutGlobalScopes()
            ->whereIn('actor_user_id', fn ($q) => $q->select('id')->from('users')->where('is_superadmin', true))
            ->with(['actor', 'target']);
    }

    protected function timezone(): string
    {
        return 'Asia/Manila';
    }
}
