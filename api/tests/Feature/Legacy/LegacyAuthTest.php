<?php

namespace Tests\Feature\Legacy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyAuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_login_returns_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'alice@acme.test', 'password' => 'password', 'company' => 'acme',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'token_type']);

        $this->freshRequest()->withToken($response->json('token'))
            ->getJson('/api/legacy/me')
            ->assertOk()
            ->assertJsonPath('email', 'alice@acme.test')
            ->assertJsonPath('company.slug', 'acme');
    }

    public function test_login_requires_the_right_company(): void
    {
        // alice existe dans acme, pas dans globex.
        $this->postJson('/api/login', [
            'email' => 'alice@acme.test', 'password' => 'password', 'company' => 'globex',
        ])->assertUnauthorized()->assertJsonPath('error', 'INVALID_CREDENTIALS');
    }

    public function test_same_email_in_two_companies_resolves_to_distinct_users(): void
    {
        $acme = $this->postJson('/api/login', ['email' => 'sam@shared.test', 'password' => 'password', 'company' => 'acme'])->json('token');
        $globex = $this->postJson('/api/login', ['email' => 'sam@shared.test', 'password' => 'password', 'company' => 'globex'])->json('token');

        $a = $this->freshRequest()->withToken($acme)->getJson('/api/legacy/me')->assertOk();
        $g = $this->freshRequest()->withToken($globex)->getJson('/api/legacy/me')->assertOk();

        $this->assertSame('acme', $a->json('company.slug'));
        $this->assertSame('globex', $g->json('company.slug'));
        $this->assertNotSame($a->json('id'), $g->json('id'));
    }

    public function test_wrong_password_and_unknown_company_give_the_same_error(): void
    {
        $bad = $this->postJson('/api/login', ['email' => 'alice@acme.test', 'password' => 'nope', 'company' => 'acme']);
        $unknown = $this->postJson('/api/login', ['email' => 'alice@acme.test', 'password' => 'password', 'company' => 'nope']);

        $bad->assertUnauthorized();
        $unknown->assertUnauthorized();
        $this->assertSame($bad->json(), $unknown->json());
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/legacy/me')->assertUnauthorized()->assertJsonPath('error', 'UNAUTHENTICATED');
        $this->getJson('/api/notes')->assertUnauthorized();
    }
}
