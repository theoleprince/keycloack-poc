<?php

namespace App\Auth\Keycloak;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client minimal de l'Admin API Keycloak, authentifié par le service account
 * `laravel-importer` (client credentials). Ne journalise jamais les corps de
 * requêtes (ils peuvent contenir un mot de passe temporaire).
 */
class KeycloakAdminClient
{
    private ?string $accessToken = null;

    private int $tokenExpiresAt = 0;

    /** @var array<string, array>|null */
    private ?array $organizations = null;

    private function base(): string
    {
        return config('keycloak.admin.base_url').'/admin/realms/'.config('keycloak.admin.realm');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(10)->acceptJson()->withToken($this->token());
    }

    private function token(): string
    {
        if ($this->accessToken && time() < $this->tokenExpiresAt - 30) {
            return $this->accessToken;
        }

        $secret = config('keycloak.admin.client_secret');
        if (! $secret) {
            throw new RuntimeException('KEYCLOAK_IMPORTER_CLIENT_SECRET non défini.');
        }

        $response = Http::asForm()->timeout(10)->post(
            config('keycloak.admin.base_url').'/realms/'.config('keycloak.admin.realm').'/protocol/openid-connect/token',
            ['grant_type' => 'client_credentials', 'client_id' => config('keycloak.admin.client_id'), 'client_secret' => $secret],
        );

        if (! $response->successful()) {
            throw new RuntimeException('Authentification du service account impossible (HTTP '.$response->status().').');
        }

        $this->accessToken = $response->json('access_token');
        $this->tokenExpiresAt = time() + (int) $response->json('expires_in', 60);

        return $this->accessToken;
    }

    /** @return array<string, mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        $users = $this->http()->get($this->base().'/users', ['email' => $email, 'exact' => 'true'])->throw()->json();

        foreach ($users as $user) {
            if (strcasecmp($user['email'] ?? '', $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function findUserByUsername(string $username): ?array
    {
        $users = $this->http()->get($this->base().'/users', ['username' => $username, 'exact' => 'true'])->throw()->json();

        foreach ($users as $user) {
            if (strcasecmp($user['username'] ?? '', $username) === 0) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Crée l'utilisateur et retourne son id (`sub`).
     *
     * @throws KeycloakConflictException si username/email déjà pris (409)
     */
    public function createUser(array $representation): string
    {
        $response = $this->http()->post($this->base().'/users', $representation);

        if ($response->status() === 409) {
            throw new KeycloakConflictException((string) ($response->json('errorMessage') ?? 'Conflit'));
        }

        $response->throw();

        $location = $response->header('Location');
        if (! $location || ! preg_match('#/users/([0-9a-f-]{36})$#', $location, $m)) {
            throw new RuntimeException('Identifiant du nouvel utilisateur introuvable (Location).');
        }

        return $m[1];
    }

    /** @return array<string, mixed>|null organisation par alias */
    public function organizationByAlias(string $alias): ?array
    {
        if ($this->organizations === null) {
            $this->organizations = [];
            foreach ($this->http()->get($this->base().'/organizations', ['max' => 1000])->throw()->json() as $org) {
                $this->organizations[$org['alias']] = $org;
            }
        }

        return $this->organizations[$alias] ?? null;
    }

    /** @return list<string> alias des organisations dont l'utilisateur est membre */
    public function userOrganizationAliases(string $userId): array
    {
        $orgs = $this->http()->get($this->base().'/organizations/members/'.$userId.'/organizations')->throw()->json();

        return array_values(array_map(fn ($o) => $o['alias'], $orgs ?? []));
    }

    public function addOrganizationMember(string $orgId, string $userId): void
    {
        // Le corps attendu est l'id utilisateur en chaîne JSON.
        $response = $this->http()->withBody(json_encode($userId), 'application/json')
            ->post($this->base().'/organizations/'.$orgId.'/members');

        if ($response->status() !== 409) {
            $response->throw();
        }
    }

    public function assignRealmRole(string $userId, string $roleName): void
    {
        $role = $this->http()->get($this->base().'/roles/'.rawurlencode($roleName))->throw()->json();

        $this->http()->post($this->base().'/users/'.$userId.'/role-mappings/realm', [
            ['id' => $role['id'], 'name' => $role['name']],
        ])->throw();
    }

    public static function describe(\Throwable $e): string
    {
        return $e instanceof RequestException
            ? 'HTTP '.$e->response->status()
            : class_basename($e).': '.$e->getMessage();
    }
}
