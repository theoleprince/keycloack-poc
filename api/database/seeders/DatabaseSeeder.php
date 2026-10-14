<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données de DEV de l'existant ("legacy").
 * Mot de passe legacy de tous les comptes : "password" (valeur de dev).
 */
class DatabaseSeeder extends Seeder
{
    public const LEGACY_PASSWORD = 'password';

    public function run(): void
    {
        $companies = collect([
            ['slug' => 'acme', 'name' => 'ACME'],
            ['slug' => 'globex', 'name' => 'Globex'],
            ['slug' => 'initech', 'name' => 'Initech'],
        ])->mapWithKeys(fn ($c) => [$c['slug'] => Company::updateOrCreate(['slug' => $c['slug']], $c)]);

        // Lien vers l'Organization Keycloak (alias = slug dans ce POC).
        foreach ($companies as $slug => $company) {
            $company->forceFill(['keycloak_org_alias' => $slug])->save();
        }

        $password = Hash::make(self::LEGACY_PASSWORD);

        // [société, nom, email, rôle, email vérifié]
        $users = [
            ['acme', 'Alice Acme', 'alice@acme.test', 'user', true],
            ['acme', 'Bob Acme', 'bob@acme.test', 'admin', true],
            ['acme', 'Eve Acme', 'eve@acme.test', 'user', false],
            ['acme', 'Sam (ACME)', 'sam@shared.test', 'user', true],
            ['globex', 'Carol Globex', 'carol@globex.test', 'user', true],
            ['globex', 'Frank Globex', 'frank@globex.test', 'admin', false],
            // Même email que dans ACME : cas d'ambiguïté volontaire.
            ['globex', 'Sam (Globex)', 'sam@shared.test', 'user', true],
            ['initech', 'Dave Initech', 'dave@initech.test', 'admin', true],
            ['initech', 'Grace Initech', 'grace@initech.test', 'user', true],
        ];

        foreach ($users as [$slug, $name, $email, $role, $verified]) {
            $user = User::firstOrNew(['company_id' => $companies[$slug]->id, 'email' => $email]);
            $user->forceFill([
                'company_id' => $companies[$slug]->id,
                'name' => $name,
                'password' => $password,
                'role' => $role,
                'email_verified_at' => $verified ? now() : null,
            ])->save();
        }

        $tenant = app(TenantContext::class);

        foreach ($companies as $slug => $company) {
            $tenant->runAs($company, function () use ($company, $slug) {
                if (Note::query()->exists()) {
                    return;
                }

                foreach (User::where('company_id', $company->id)->get() as $user) {
                    $note = new Note(['title' => "Note de {$user->name}", 'body' => "Donnée confidentielle {$slug}"]);
                    $note->user_id = $user->id;
                    $note->save();
                }

                $owner = User::where('company_id', $company->id)->orderBy('id')->first();
                foreach (['Migration ERP', 'Refonte site'] as $name) {
                    $project = new Project(['name' => "{$name} ({$slug})"]);
                    $project->user_id = $owner->id;
                    $project->save();
                }
            });
        }
    }
}
