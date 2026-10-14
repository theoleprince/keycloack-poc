<?php

namespace Tests\Feature\Keycloak;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithKeycloak;
use Tests\TestCase;

class TokenValidationTest extends TestCase
{
    use InteractsWithKeycloak, RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpKeycloak();
    }

    public function test_valid_token_is_accepted(): void
    {
        $this->asKeycloak($this->kcToken())->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('auth_method', 'keycloak')
            ->assertJsonPath('company.slug', 'acme')
            ->assertJsonPath('api_url', 'http://10.0.2.2:8000');
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->asKeycloak($this->kcToken(['exp' => time() - 10, 'iat' => time() - 400]))->getJson('/api/me')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN');
    }

    public function test_token_without_exp_is_rejected(): void
    {
        $this->asKeycloak($this->kcToken(['exp' => null]))->getJson('/api/me')->assertUnauthorized();
    }

    public function test_not_yet_valid_token_is_rejected(): void
    {
        $this->asKeycloak($this->kcToken(['nbf' => time() + 120]))->getJson('/api/me')->assertUnauthorized();
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        // Issuer étranger : pas aiguillé vers Keycloak, et refusé par Sanctum.
        $this->asKeycloak($this->kcToken(['iss' => 'http://evil.test/realms/plateforme']))->getJson('/api/me')
            ->assertUnauthorized();
        // Même hôte, autre realm.
        $this->asKeycloak($this->kcToken(['iss' => 'http://kc.test/realms/autre']))->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $this->asKeycloak($this->kcToken(['aud' => ['account']]))->getJson('/api/me')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN');
    }

    public function test_wrong_azp_is_rejected(): void
    {
        $this->asKeycloak($this->kcToken(['azp' => 'projet-2']))->getJson('/api/me')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN');
    }

    public function test_forged_signature_is_rejected(): void
    {
        // Signé par une autre clé mais en annonçant le kid légitime.
        $this->asKeycloak($this->kcToken([], 'attacker'))->getJson('/api/me')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN');
    }

    public function test_tampered_payload_is_rejected(): void
    {
        [$h, , $s] = explode('.', $this->kcToken());
        $payload = rtrim(strtr(base64_encode(json_encode($this->claims(['organization' => ['globex']]))), '+/', '-_'), '=');

        $this->asKeycloak("$h.$payload.$s")->getJson('/api/me')->assertUnauthorized();
    }

    public function test_alg_none_is_rejected(): void
    {
        $this->asKeycloak($this->unsignedToken())->getJson('/api/me')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN');
    }

    public function test_hs256_algorithm_confusion_is_rejected(): void
    {
        // Attaque classique : HMAC avec la clé PUBLIQUE comme secret.
        $public = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey()))['key'];
        $token = JWT::encode($this->claims(), $public, 'HS256', $this->kcKid);

        $this->asKeycloak($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_id_token_type_is_rejected(): void
    {
        $this->asKeycloak($this->kcToken(['typ' => 'ID']))->getJson('/api/me')->assertUnauthorized();
    }

    public function test_unknown_kid_triggers_a_single_jwks_refresh(): void
    {
        // Le cache contient l'ancienne clé ; Keycloak a fait une rotation.
        $this->asKeycloak($this->kcToken())->getJson('/api/me')->assertOk();

        $this->fakeJwks($this->jwks([$this->kcKid, 'rotated-kid']));

        $this->asKeycloak($this->kcToken([], 'test', 'rotated-kid'))->getJson('/api/me')->assertOk();
    }

    public function test_jwks_is_cached(): void
    {
        $this->asKeycloak($this->kcToken())->getJson('/api/me')->assertOk();
        $this->asKeycloak($this->kcToken())->getJson('/api/me')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_keycloak_unreachable_without_cache_returns_503_and_no_data(): void
    {
        $this->fakeJwksDown();

        $this->asKeycloak($this->kcToken())->getJson('/api/notes')
            ->assertStatus(503)
            ->assertJsonPath('error', 'AUTH_PROVIDER_UNAVAILABLE')
            ->assertJsonMissingPath('data');
    }

    public function test_jwks_server_error_returns_503(): void
    {
        $this->fakeJwksError(500);

        $this->asKeycloak($this->kcToken())->getJson('/api/me')->assertStatus(503);
    }

    public function test_same_code_configured_for_projet_2_rejects_projet_1_tokens(): void
    {
        config(['keycloak.client_id' => 'projet-2']);

        $this->asKeycloak($this->kcToken())->getJson('/api/me')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN');

        $this->asKeycloak($this->kcToken(['aud' => ['projet-2', 'account'], 'azp' => 'projet-2']))->getJson('/api/me')
            ->assertOk();
    }

    public function test_garbage_bearer_is_rejected(): void
    {
        $this->asKeycloak('not-a-token')->getJson('/api/me')->assertUnauthorized();
        $this->asKeycloak('a.b.c')->getJson('/api/me')->assertUnauthorized();
    }
}
