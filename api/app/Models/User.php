<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Pas de global scope sur User : l'authentification (Sanctum, Keycloak) doit
 * pouvoir retrouver un utilisateur AVANT que la société courante soit connue.
 * Les listings métier passent par le scope local forCurrentCompany().
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        $tenant = app(TenantContext::class);

        return $tenant->has()
            ? $query->where('company_id', $tenant->id())
            : $query->whereRaw('1 = 0');
    }
}
