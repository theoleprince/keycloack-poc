<?php

namespace App\Tenancy;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Cloisonnement par société.
 *
 * - Global scope : toute requête est filtrée sur la société du TenantContext.
 *   Sans société courante -> aucune ligne (fail-closed).
 * - Création : company_id est TOUJOURS imposé depuis le TenantContext, même si
 *   une autre valeur a été fournie (impossible de forcer company_id).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $tenant = app(TenantContext::class);
            $column = $builder->getModel()->qualifyColumn('company_id');

            if (! $tenant->has()) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($column, $tenant->id());
        });

        static::creating(function ($model) {
            $tenant = app(TenantContext::class);

            if ($tenant->has()) {
                $model->company_id = $tenant->id();

                return;
            }

            if (empty($model->company_id)) {
                throw new LogicException('Création sans société courante ni company_id explicite.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('company_id')) {
                throw new LogicException('Le changement de société d\'un enregistrement est interdit.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Accès hors périmètre société : réservé aux seeders, commandes d'admin
     * et tests. Jamais depuis un contrôleur.
     */
    public static function withoutCompanyScope(): Builder
    {
        return static::withoutGlobalScope('company');
    }
}
