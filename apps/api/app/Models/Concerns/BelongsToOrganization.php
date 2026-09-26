<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// every tenant table has an `organization_id`. While a request works inside an organization (see
// OrganizationContext) every query on the model is limited to it and new rows get it filled in, so an id from
// another office is simply "not found". A row that already names an organization keeps it.
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query): void {
            $id = app(OrganizationContext::class)->id();
            if ($id !== null) {
                $query->where($query->getModel()->getTable().'.organization_id', $id);
            }
        });

        static::creating(function (Model $model): void {
            // platform superadmins belong to no organization, even when one is made while a request works inside one
            if ($model->getAttribute('organization_id') === null && ! $model->getAttribute('is_superadmin')) {
                $model->setAttribute('organization_id', app(OrganizationContext::class)->id());
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
