<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// A work item a manager assigns to one or more people of their organization (docs/DEVELOPMENT_PLAN.md). An
// employee tags a tracked session with a task they were assigned, or leaves it untagged (general time).
#[Fillable(['title', 'description', 'status'])]
class Task extends Model
{
    use BelongsToOrganization, HasFactory;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User, $this> */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignments')
            ->withPivot('assigned_by', 'assigned_at');
    }
}
