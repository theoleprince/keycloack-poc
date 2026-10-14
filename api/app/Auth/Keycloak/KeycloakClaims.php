<?php

namespace App\Auth\Keycloak;

/**
 * Claims utiles d'un access token Keycloak déjà VALIDÉ.
 */
final class KeycloakClaims
{
    /**
     * @param  list<string>  $roles  realm_access.roles
     * @param  mixed  $organization  claim brut `organization` (liste d'alias en
     *                               Keycloak 26.7, ou objet si le mapper est configuré
     *                               avec les attributs/id d'organisation)
     */
    public function __construct(
        public readonly string $sub,
        public readonly ?string $preferredUsername,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly array $roles,
        public readonly ?string $apiUrl,
        public readonly mixed $organization,
        public readonly string $clientId,
    ) {}

    public static function fromPayload(object $payload): self
    {
        $data = json_decode(json_encode($payload), true);
        $roles = $data['realm_access']['roles'] ?? [];

        return new self(
            sub: (string) $data['sub'],
            preferredUsername: isset($data['preferred_username']) ? (string) $data['preferred_username'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            emailVerified: ($data['email_verified'] ?? false) === true,
            roles: array_values(array_filter(is_array($roles) ? $roles : [], 'is_string')),
            apiUrl: isset($data['api_url']) && is_string($data['api_url']) ? $data['api_url'] : null,
            organization: $data['organization'] ?? null,
            clientId: (string) ($data['azp'] ?? ''),
        );
    }

    /**
     * Alias d'organisation présents dans le jeton.
     * Formats acceptés :
     *  - ["acme"]                       (défaut Keycloak 26.7, vérifié)
     *  - {"acme": {"id": "…", …}}       (mapper avec attributs / id)
     *  - "acme"                          (mapper mono-valué)
     *
     * @return list<string>
     */
    public function organizationAliases(): array
    {
        $org = $this->organization;

        if (is_string($org)) {
            $aliases = [$org];
        } elseif (is_array($org) && array_is_list($org)) {
            $aliases = array_filter($org, 'is_string');
        } elseif (is_array($org)) {
            $aliases = array_keys($org);
        } else {
            $aliases = [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $aliases), fn ($a) => trim($a) !== '')));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
