<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Jetons "Keycloak" signés localement + JWKS mocké (Http::fake).
 */
trait InteractsWithKeycloak
{
    protected string $kcIssuer = 'http://kc.test/realms/plateforme';

    protected string $kcJwksUrl = 'http://kc.test/realms/plateforme/protocol/openid-connect/certs';

    protected string $kcKid = 'test-kid-1';

    /** Sujet par défaut des jetons du test (stable d'un appel à l'autre). */
    protected string $kcSub;

    /** État du faux Keycloak : array JWKS, 'down' ou un code HTTP d'erreur. */
    protected array|string|int $jwksState = [];

    protected function setUpKeycloak(string $clientId = 'projet-1'): void
    {
        config([
            'keycloak.issuer' => $this->kcIssuer,
            'keycloak.client_id' => $clientId,
            'keycloak.jwks_url' => $this->kcJwksUrl,
            'keycloak.leeway' => 0,
        ]);
        Cache::flush();
        $this->kcSub = (string) Str::uuid();
        $this->jwksState = $this->jwks();

        // Un seul stub (Http::fake empile les stubs, le premier gagne) piloté par l'état.
        Http::fake([$this->kcJwksUrl => function () {
            return match (true) {
                $this->jwksState === 'down' => throw new ConnectionException('Connection refused'),
                is_int($this->jwksState) => Http::response('error', $this->jwksState),
                default => Http::response($this->jwksState),
            };
        }]);
    }

    protected function fakeJwks(?array $jwks = null): void
    {
        $this->jwksState = $jwks ?? $this->jwks();
    }

    protected function fakeJwksDown(): void
    {
        $this->jwksState = 'down';
    }

    protected function fakeJwksError(int $status = 500): void
    {
        $this->jwksState = $status;
    }

    protected function privateKey(string $name = 'test'): string
    {
        return file_get_contents(base_path("tests/Fixtures/keys/{$name}-private.pem"));
    }

    protected function publicJwk(string $kid, string $name = 'test'): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey($name)));
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return ['kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    }

    /** JWKS au format Keycloak : clé de signature + clé de chiffrement (ignorée). */
    protected function jwks(array $kids = null): array
    {
        $keys = array_map(fn ($kid) => $this->publicJwk($kid), $kids ?? [$this->kcKid]);
        $enc = $this->publicJwk('enc-kid');
        $enc['alg'] = 'RSA-OAEP';
        $enc['use'] = 'enc';
        $keys[] = $enc;

        return ['keys' => $keys];
    }

    protected function claims(array $overrides = []): array
    {
        $now = time();

        return array_merge([
            'exp' => $now + 300,
            'iat' => $now,
            'auth_time' => $now,
            'jti' => (string) Str::uuid(),
            'iss' => $this->kcIssuer,
            'aud' => ['projet-1', 'account'],
            'sub' => $this->kcSub,
            'typ' => 'Bearer',
            'azp' => 'projet-1',
            'realm_access' => ['roles' => ['default-roles-plateforme', 'offline_access', 'user']],
            'scope' => 'openid organization offline_access profile email',
            'email_verified' => true,
            'organization' => ['acme'],
            'preferred_username' => 'someone',
            'email' => 'someone@acme.test',
            'api_url' => 'http://10.0.2.2:8000',
        ], $overrides);
    }

    protected function kcToken(array $overrides = [], string $key = 'test', ?string $kid = null): string
    {
        $claims = array_filter($this->claims($overrides), fn ($v) => $v !== null);

        return JWT::encode($claims, $this->privateKey($key), 'RS256', $kid ?? $this->kcKid);
    }

    /** Jeton non signé (`alg: none`). */
    protected function unsignedToken(array $overrides = []): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');

        return $b64(['alg' => 'none', 'typ' => 'JWT', 'kid' => $this->kcKid]).'.'.$b64($this->claims($overrides)).'.';
    }

    protected function asKeycloak(string $token): static
    {
        return $this->freshRequest()->withToken($token);
    }
}
