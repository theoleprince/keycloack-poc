<?php

namespace Tests\Feature;

use Tests\TestCase;

class DiscoveryAndHealthTest extends TestCase
{
    public function test_health_is_public(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_known_key_returns_backend_and_keycloak_info(): void
    {
        $this->getJson('/api/discover?key=ACME-7K2P-DEV')
            ->assertOk()
            ->assertExactJson([
                'company_slug' => 'acme',
                'api_url' => config('discovery.keys.ACME-7K2P-DEV.api_url'),
                'keycloak_issuer' => config('keycloak.issuer'),
                'client_id' => config('keycloak.client_id'),
                'org_alias' => 'acme',
            ]);
    }

    public function test_unknown_key_returns_the_generic_404(): void
    {
        $unknown = $this->getJson('/api/discover?key=NOPE')->assertNotFound();
        $prefix = $this->getJson('/api/discover?key=ACME-7K2P')->assertNotFound();
        $empty = $this->getJson('/api/discover')->assertNotFound();
        $generic = $this->getJson('/api/does-not-exist')->assertNotFound();

        $this->assertSame($generic->json(), $unknown->json());
        $this->assertSame($unknown->json(), $prefix->json());
        $this->assertSame($unknown->json(), $empty->json());
    }

    public function test_discovery_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/discover?key=NOPE')->assertNotFound();
        }

        $this->getJson('/api/discover?key=ACME-7K2P-DEV')->assertStatus(429);
    }
}
