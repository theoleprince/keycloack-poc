<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les guards (Sanctum…) mémorisent l'utilisateur entre deux appels d'un même
     * test ; on les réinitialise pour simuler des requêtes indépendantes.
     */
    protected function freshRequest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this;
    }

    protected function legacyToken(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    protected function asLegacy(User $user): static
    {
        return $this->freshRequest()->withToken($this->legacyToken($user));
    }

    protected function company(string $slug): Company
    {
        return Company::where('slug', $slug)->firstOrFail();
    }

    protected function seededUser(string $slug, string $email): User
    {
        return User::where('company_id', $this->company($slug)->id)->where('email', $email)->firstOrFail();
    }

    /** Lecture hors scope, réservée aux assertions de test. */
    protected function inCompany(string $slug, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->company($slug), $callback);
    }
}
