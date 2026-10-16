<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current tenant and fills tenant_id on create.
 *
 * The scope fails closed: without a tenant in the context nothing is returned.
 * Console code and platform-wide queries opt out with withoutGlobalScope('tenant').
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);

            $context->check()
                ? $builder->where($builder->qualifyColumn('tenant_id'), $context->id())
                : $builder->whereRaw('1 = 0');
        });

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if (! $model->getAttribute('tenant_id') && $context->check()) {
                $model->setAttribute('tenant_id', $context->id());
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
