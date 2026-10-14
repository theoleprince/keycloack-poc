<?php

namespace Tests\Feature\Legacy;

use App\Models\Note;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_acme_user_only_sees_acme_notes_and_projects(): void
    {
        $alice = $this->seededUser('acme', 'alice@acme.test');
        $acmeId = $this->company('acme')->id;

        $notes = $this->asLegacy($alice)->getJson('/api/notes')->assertOk()->json('data');
        $this->assertNotEmpty($notes);
        $this->assertSame([$acmeId], array_values(array_unique(array_column($notes, 'company_id'))));

        $projects = $this->asLegacy($alice)->getJson('/api/projects')->assertOk()->json('data');
        $this->assertNotEmpty($projects);
        $this->assertSame([$acmeId], array_values(array_unique(array_column($projects, 'company_id'))));
    }

    public function test_direct_access_to_own_company_resource_works(): void
    {
        $alice = $this->seededUser('acme', 'alice@acme.test');
        $acmeNote = $this->inCompany('acme', fn () => Note::first());
        $acmeProject = $this->inCompany('acme', fn () => Project::first());

        $this->asLegacy($alice)->getJson("/api/notes/{$acmeNote->id}")->assertOk()->assertJsonPath('data.id', $acmeNote->id);
        $this->asLegacy($alice)->getJson("/api/projects/{$acmeProject->id}")->assertOk();
    }

    public function test_direct_access_to_another_company_resource_returns_404(): void
    {
        $alice = $this->seededUser('acme', 'alice@acme.test');
        $globexNote = $this->inCompany('globex', fn () => Note::first());
        $globexProject = $this->inCompany('globex', fn () => Project::first());

        $this->asLegacy($alice)->getJson("/api/notes/{$globexNote->id}")
            ->assertNotFound()->assertJsonPath('error', 'NOT_FOUND');
        $this->asLegacy($alice)->getJson("/api/projects/{$globexProject->id}")->assertNotFound();
    }

    public function test_created_note_belongs_to_the_user_company_even_if_company_id_is_forced(): void
    {
        $carol = $this->seededUser('globex', 'carol@globex.test');
        $acmeId = $this->company('acme')->id;

        $response = $this->asLegacy($carol)->postJson('/api/notes', [
            'title' => 'Tentative', 'company_id' => $acmeId, 'user_id' => 1,
        ])->assertCreated();

        $this->assertSame($this->company('globex')->id, $response->json('data.company_id'));
        $this->assertSame($carol->id, $response->json('data.user_id'));
    }

    public function test_without_tenant_no_row_is_visible(): void
    {
        app(TenantContext::class)->clear();

        $this->assertSame(0, Note::count());
        $this->assertSame(0, Project::count());
        $this->assertGreaterThan(0, Note::withoutCompanyScope()->count());
    }

    public function test_same_email_users_do_not_share_data(): void
    {
        $samAcme = $this->seededUser('acme', 'sam@shared.test');
        $samGlobex = $this->seededUser('globex', 'sam@shared.test');

        $acmeTitles = array_column($this->asLegacy($samAcme)->getJson('/api/notes')->json('data'), 'body');
        $globexTitles = array_column($this->asLegacy($samGlobex)->getJson('/api/notes')->json('data'), 'body');

        $this->assertSame(['Donnée confidentielle acme'], array_values(array_unique($acmeTitles)));
        $this->assertSame(['Donnée confidentielle globex'], array_values(array_unique($globexTitles)));
    }
}
