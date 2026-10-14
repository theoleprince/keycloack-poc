<?php

namespace Tests\Feature\Keycloak;

use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithKeycloak;
use Tests\TestCase;

class TenantAndProvisioningTest extends TestCase
{
    use InteractsWithKeycloak, RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpKeycloak();
    }

    // --- Société (claim organization) ---------------------------------------

    public function test_missing_organization_claim_is_forbidden(): void
    {
        $this->asKeycloak($this->kcToken(['organization' => null]))->getJson('/api/me')
            ->assertForbidden()->assertJsonPath('error', 'NO_COMPANY');
    }

    public function test_empty_organization_claim_is_forbidden(): void
    {
        $this->asKeycloak($this->kcToken(['organization' => []]))->getJson('/api/notes')
            ->assertForbidden()->assertJsonPath('error', 'NO_COMPANY');
    }

    public function test_unknown_organization_is_forbidden(): void
    {
        $this->asKeycloak($this->kcToken(['organization' => ['umbrella']]))->getJson('/api/notes')
            ->assertForbidden()->assertJsonPath('error', 'NO_COMPANY');
    }

    public function test_multiple_organizations_are_forbidden(): void
    {
        $this->asKeycloak($this->kcToken(['organization' => ['acme', 'globex']]))->getJson('/api/notes')
            ->assertForbidden()->assertJsonPath('error', 'MULTIPLE_COMPANIES');
    }

    public function test_organization_claim_object_format_is_supported(): void
    {
        $this->asKeycloak($this->kcToken(['organization' => ['globex' => ['id' => (string) Str::uuid()]], 'email' => 'x@globex.test']))
            ->getJson('/api/me')->assertOk()->assertJsonPath('company.slug', 'globex');
    }

    public function test_user_whose_company_differs_from_token_is_forbidden(): void
    {
        $carol = $this->seededUser('globex', 'carol@globex.test');
        $carol->forceFill(['keycloak_id' => $sub = (string) Str::uuid()])->save();

        $this->asKeycloak($this->kcToken(['sub' => $sub, 'organization' => ['acme'], 'email' => 'carol@globex.test']))
            ->getJson('/api/notes')
            ->assertForbidden()->assertJsonPath('error', 'COMPANY_MISMATCH')->assertJsonMissingPath('data');
    }

    // --- Isolation ------------------------------------------------------------

    public function test_acme_token_never_reads_globex_data(): void
    {
        $token = $this->kcToken(['organization' => ['acme']]);
        $acmeId = $this->company('acme')->id;

        $notes = $this->asKeycloak($token)->getJson('/api/notes')->assertOk()->json('data');
        $projects = $this->asKeycloak($token)->getJson('/api/projects')->assertOk()->json('data');

        $this->assertNotEmpty($notes);
        $this->assertSame([$acmeId], array_values(array_unique(array_column($notes, 'company_id'))));
        $this->assertSame([$acmeId], array_values(array_unique(array_column($projects, 'company_id'))));
    }

    public function test_direct_id_access_to_other_company_is_404_not_403(): void
    {
        $token = $this->kcToken(['organization' => ['acme']]);
        $globexNote = $this->inCompany('globex', fn () => Note::first());
        $globexProject = $this->inCompany('globex', fn () => Project::first());
        $acmeNote = $this->inCompany('acme', fn () => Note::first());

        $this->asKeycloak($token)->getJson("/api/notes/{$globexNote->id}")->assertNotFound()->assertJsonPath('error', 'NOT_FOUND');
        $this->asKeycloak($token)->getJson("/api/projects/{$globexProject->id}")->assertNotFound();
        $this->asKeycloak($token)->getJson("/api/notes/{$acmeNote->id}")->assertOk();
        // Même réponse que pour un id inexistant : rien n'est révélé.
        $this->assertSame(
            $this->asKeycloak($token)->getJson("/api/notes/{$globexNote->id}")->json(),
            $this->asKeycloak($token)->getJson('/api/notes/999999')->json(),
        );
    }

    public function test_created_note_uses_token_company_and_ignores_forced_company_id(): void
    {
        $globexId = $this->company('globex')->id;

        $response = $this->asKeycloak($this->kcToken(['organization' => ['acme']]))
            ->postJson('/api/notes', ['title' => 'Via Keycloak', 'company_id' => $globexId])
            ->assertCreated();

        $this->assertSame($this->company('acme')->id, $response->json('data.company_id'));
        $this->assertSame(0, Note::withoutCompanyScope()->where('company_id', $globexId)->where('title', 'Via Keycloak')->count());
    }

    // --- Provisionnement ------------------------------------------------------

    public function test_first_request_creates_user_second_does_not_duplicate_and_username_is_updated(): void
    {
        $sub = (string) Str::uuid();
        $before = User::count();

        $first = $this->asKeycloak($this->kcToken(['sub' => $sub, 'preferred_username' => 'newbie', 'email' => 'newbie@acme.test']))
            ->getJson('/api/me')->assertOk();
        $second = $this->asKeycloak($this->kcToken(['sub' => $sub, 'preferred_username' => 'newbie2', 'email' => 'newbie@acme.test']))
            ->getJson('/api/me')->assertOk();

        $this->assertSame($before + 1, User::count());
        $this->assertSame($first->json('local_user_id'), $second->json('local_user_id'));
        $this->assertSame('newbie2', User::where('keycloak_id', $sub)->value('username'));
        $this->assertNull(User::where('keycloak_id', $sub)->value('password'));
        $this->assertSame($this->company('acme')->id, User::where('keycloak_id', $sub)->value('company_id'));
    }

    public function test_keycloak_id_is_unique_at_database_level(): void
    {
        $sub = (string) Str::uuid();
        $make = fn ($email) => User::factory()->create(['company_id' => $this->company('acme')->id, 'email' => $email])
            ->forceFill(['keycloak_id' => $sub])->save();

        $make('a@acme.test');

        $this->expectException(UniqueConstraintViolationException::class);
        $make('b@acme.test');
    }

    // --- Rattachement par email -----------------------------------------------

    public function test_verified_email_links_to_existing_account_in_same_company(): void
    {
        $alice = $this->seededUser('acme', 'alice@acme.test');
        $sub = (string) Str::uuid();

        $this->asKeycloak($this->kcToken(['sub' => $sub, 'email' => 'alice@acme.test', 'email_verified' => true, 'preferred_username' => 'alice']))
            ->getJson('/api/me')->assertOk()->assertJsonPath('local_user_id', $alice->id);

        $this->assertSame($sub, $alice->fresh()->keycloak_id);
    }

    public function test_unverified_email_never_links(): void
    {
        $alice = $this->seededUser('acme', 'alice@acme.test');
        $before = User::count();

        $this->asKeycloak($this->kcToken(['email' => 'alice@acme.test', 'email_verified' => false]))
            ->getJson('/api/me')->assertForbidden()->assertJsonPath('error', 'EMAIL_NOT_VERIFIED');

        $this->assertNull($alice->fresh()->keycloak_id);
        $this->assertSame($before, User::count());
    }

    public function test_email_of_another_company_never_links(): void
    {
        $carol = $this->seededUser('globex', 'carol@globex.test');

        $response = $this->asKeycloak($this->kcToken(['email' => 'carol@globex.test', 'email_verified' => true, 'organization' => ['acme']]))
            ->getJson('/api/me')->assertOk();

        $this->assertNull($carol->fresh()->keycloak_id);
        $this->assertNotSame($carol->id, $response->json('local_user_id'));
        $this->assertSame('acme', $response->json('company.slug'));
    }

    public function test_shared_email_links_only_inside_token_company(): void
    {
        $samAcme = $this->seededUser('acme', 'sam@shared.test');
        $samGlobex = $this->seededUser('globex', 'sam@shared.test');

        $this->asKeycloak($this->kcToken(['email' => 'sam@shared.test', 'organization' => ['globex']]))
            ->getJson('/api/me')->assertOk()->assertJsonPath('local_user_id', $samGlobex->id);

        $this->assertNull($samAcme->fresh()->keycloak_id);
    }

    // --- Cohabitation Sanctum / Keycloak ----------------------------------------

    public function test_sanctum_and_keycloak_resolve_the_same_local_user_and_notes(): void
    {
        $alice = $this->seededUser('acme', 'alice@acme.test');
        $kc = $this->kcToken(['email' => 'alice@acme.test', 'preferred_username' => 'alice']);

        $viaKeycloak = $this->asKeycloak($kc)->getJson('/api/me')->assertOk();
        $viaSanctum = $this->asLegacy($alice)->getJson('/api/me')->assertOk();

        $this->assertSame($viaKeycloak->json('local_user_id'), $viaSanctum->json('local_user_id'));
        $this->assertSame('keycloak', $viaKeycloak->json('auth_method'));
        $this->assertSame('sanctum', $viaSanctum->json('auth_method'));
        $this->assertSame(
            $this->asLegacy($alice)->getJson('/api/notes')->json('data'),
            $this->asKeycloak($kc)->getJson('/api/notes')->json('data'),
        );

        // L'ancien endpoint legacy fonctionne toujours.
        $this->asLegacy($alice)->getJson('/api/legacy/me')->assertOk()->assertJsonPath('id', $alice->id);
    }

    // --- Rôle admin ------------------------------------------------------------

    public function test_admin_lists_only_users_of_own_company(): void
    {
        $bob = $this->seededUser('acme', 'bob@acme.test');
        $token = $this->kcToken([
            'email' => 'bob@acme.test', 'preferred_username' => 'bob',
            'realm_access' => ['roles' => ['user', 'admin']],
        ]);

        $users = $this->asKeycloak($token)->getJson('/api/admin/users')->assertOk()->json('data');

        $this->assertNotEmpty($users);
        $this->assertSame([$this->company('acme')->id], array_values(array_unique(array_column($users, 'company_id'))));
        $this->assertContains($bob->id, array_column($users, 'id'));
    }

    public function test_plain_user_gets_403_on_admin_route(): void
    {
        $this->asKeycloak($this->kcToken(['email' => 'alice@acme.test']))->getJson('/api/admin/users')
            ->assertForbidden()->assertJsonPath('error', 'FORBIDDEN_ROLE');
    }

    public function test_admin_role_works_with_sanctum_too(): void
    {
        $this->asLegacy($this->seededUser('acme', 'bob@acme.test'))->getJson('/api/admin/users')->assertOk();
        $this->asLegacy($this->seededUser('acme', 'alice@acme.test'))->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_local_admin_flag_is_not_enough_with_keycloak(): void
    {
        // Le rôle vient du jeton, pas de la base locale.
        $this->asKeycloak($this->kcToken(['email' => 'bob@acme.test', 'realm_access' => ['roles' => ['user']]]))
            ->getJson('/api/admin/users')->assertForbidden();
    }
}
