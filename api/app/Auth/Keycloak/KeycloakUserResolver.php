<?php

namespace App\Auth\Keycloak;

use App\Auth\AuthException;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jeton Keycloak validé -> société + User local.
 *
 * Règles (ordre strict) :
 *  1. Société = claim `organization` -> companies.keycloak_org_alias.
 *     Absent/vide/inconnu -> 403 NO_COMPANY ; plusieurs -> 403 MULTIPLE_COMPANIES.
 *  2. TenantContext initialisé AVANT toute requête métier.
 *  3. Utilisateur :
 *     a. par keycloak_id = sub ; sa société doit être celle du jeton
 *        (sinon 403 COMPANY_MISMATCH) ;
 *     b. sinon, SEULEMENT si email_verified = true : compte local non lié
 *        (keycloak_id NULL) avec le même email DANS LA MÊME SOCIÉTÉ -> on lui
 *        affecte le sub ;
 *     c. sinon création à la volée (JIT) dans la société du jeton.
 *  4. Mise à jour de username / email.
 *  5. Concurrence : contrainte unique sur keycloak_id + transaction ; en cas
 *     de course, on relit la ligne gagnante (aucun doublon).
 */
class KeycloakUserResolver
{
    public function __construct(private TenantContext $tenant) {}

    public function resolve(KeycloakClaims $claims): User
    {
        $company = $this->resolveCompany($claims);
        $this->tenant->set($company);

        $user = $this->findOrProvision($claims, $company);
        $this->syncProfile($user, $claims);

        return $user;
    }

    private function resolveCompany(KeycloakClaims $claims): Company
    {
        $aliases = $claims->organizationAliases();

        if (count($aliases) > 1) {
            throw AuthException::forbidden('MULTIPLE_COMPANIES', 'Le compte est rattaché à plusieurs sociétés : non supporté.');
        }

        $company = $aliases === [] ? null : Company::where('keycloak_org_alias', $aliases[0])->first();

        if (! $company) {
            throw AuthException::forbidden('NO_COMPANY', 'Aucune société valide associée à ce compte.');
        }

        return $company;
    }

    private function findOrProvision(KeycloakClaims $claims, Company $company): User
    {
        // a. Identité Keycloak déjà connue.
        if ($user = User::where('keycloak_id', $claims->sub)->first()) {
            return $this->assertSameCompany($user, $company);
        }

        // b. Rattachement d'un compte existant, uniquement si email vérifié
        //    et uniquement dans la société du jeton.
        if ($claims->emailVerified && $claims->email) {
            $candidate = User::where('company_id', $company->id)
                ->whereNull('keycloak_id')
                ->where('email', $claims->email)
                ->first();

            if ($candidate) {
                // UPDATE conditionnel : atomique face à une requête concurrente.
                $linked = User::whereKey($candidate->id)->whereNull('keycloak_id')
                    ->update(['keycloak_id' => $claims->sub]);

                if ($linked === 1) {
                    Log::info('Compte local rattaché à Keycloak', ['user_id' => $candidate->id, 'company' => $company->slug]);

                    return $candidate->refresh();
                }

                return $this->assertSameCompany(User::where('keycloak_id', $claims->sub)->firstOrFail(), $company);
            }
        }

        // Un compte local non lié porte cet email dans la société mais l'email
        // n'est pas vérifié : on refuse le rattachement ET la création (qui
        // créerait un doublon). Traitement : vérifier l'email dans Keycloak.
        if ($claims->email && User::where('company_id', $company->id)->where('email', $claims->email)->exists()) {
            throw AuthException::forbidden(
                $claims->emailVerified ? 'ACCOUNT_CONFLICT' : 'EMAIL_NOT_VERIFIED',
                $claims->emailVerified
                    ? 'Un autre compte utilise déjà cet email dans la société.'
                    : 'Email non vérifié : rattachement au compte existant impossible.'
            );
        }

        // c. Provisionnement à la volée (JIT).
        return $this->createJit($claims, $company);
    }

    private function createJit(KeycloakClaims $claims, Company $company): User
    {
        try {
            return DB::transaction(function () use ($claims, $company) {
                $user = new User;
                $user->forceFill([
                    'keycloak_id' => $claims->sub,
                    'company_id' => $company->id,
                    'name' => $claims->preferredUsername ?? $claims->email ?? $claims->sub,
                    'username' => $claims->preferredUsername,
                    'email' => $claims->email,
                    'email_verified_at' => $claims->emailVerified ? now() : null,
                    'password' => null,
                    'role' => $claims->hasRole('admin') ? 'admin' : 'user',
                ])->save();

                Log::info('Utilisateur créé depuis Keycloak (JIT)', ['user_id' => $user->id, 'company' => $company->slug]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Requête concurrente arrivée la première : on relit sa ligne.
            $user = User::where('keycloak_id', $claims->sub)->first();
            if (! $user) {
                throw AuthException::forbidden('ACCOUNT_CONFLICT', 'Un autre compte utilise déjà cet email dans la société.');
            }

            return $this->assertSameCompany($user, $company);
        }
    }

    private function assertSameCompany(User $user, Company $company): User
    {
        if ((int) $user->company_id !== (int) $company->id) {
            Log::warning('Société du jeton différente de celle du compte local', ['user_id' => $user->id]);

            throw AuthException::forbidden('COMPANY_MISMATCH', 'La société du jeton ne correspond pas à celle du compte.');
        }

        return $user;
    }

    /** Mise à jour username / email (source de vérité : Keycloak). */
    private function syncProfile(User $user, KeycloakClaims $claims): void
    {
        $changes = [];

        if ($claims->preferredUsername && $user->username !== $claims->preferredUsername) {
            $changes['username'] = $claims->preferredUsername;
        }

        // L'email n'est mis à jour que s'il est vérifié et libre dans la société.
        if ($claims->email && $claims->emailVerified && $user->email !== $claims->email
            && ! User::where('company_id', $user->company_id)->where('email', $claims->email)->whereKeyNot($user->id)->exists()) {
            $changes['email'] = $claims->email;
        }

        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }
    }
}
