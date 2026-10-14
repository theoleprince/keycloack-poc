<?php

namespace Tests\Feature\Keycloak;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Faux Admin API Keycloak en mémoire (utilisateurs + organisations).
 */
class ImportUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $kcUsers = [];

    private array $orgs = [];

    private array $members = [];

    private array $realmRoles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'keycloak.admin.base_url' => 'http://kc-admin.test',
            'keycloak.admin.realm' => 'plateforme',
            'keycloak.admin.client_secret' => 'test-secret',
            'keycloak.admin.import_temp_password' => 'Temp#Secret-NeverLogged',
        ]);

        foreach (['acme', 'globex', 'initech'] as $alias) {
            $this->orgs[$alias] = ['id' => (string) Str::uuid(), 'alias' => $alias, 'name' => ucfirst($alias)];
            $this->members[$alias] = [];
        }

        // alice existe déjà dans Keycloak (realm de démo), membre d'acme.
        $this->addKcUser('alice', 'alice@acme.test', 'acme');

        Http::fake(fn (Request $r) => $this->route($r));
    }

    private function addKcUser(string $username, string $email, ?string $org): string
    {
        $id = (string) Str::uuid();
        $this->kcUsers[$id] = ['id' => $id, 'username' => $username, 'email' => $email];
        if ($org) {
            $this->members[$org][] = $id;
        }

        return $id;
    }

    private function route(Request $r)
    {
        $path = parse_url($r->url(), PHP_URL_PATH);
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $base = '/admin/realms/plateforme';

        if ($path === '/realms/plateforme/protocol/openid-connect/token') {
            return Http::response(['access_token' => 'admin-token', 'expires_in' => 300]);
        }
        if ($path === "$base/users" && $r->method() === 'GET') {
            return Http::response(array_values(array_filter($this->kcUsers, fn ($u) => isset($q['email'])
                ? strcasecmp($u['email'], $q['email']) === 0
                : strcasecmp($u['username'], $q['username'] ?? '') === 0)));
        }
        if ($path === "$base/users" && $r->method() === 'POST') {
            $data = $r->data();
            foreach ($this->kcUsers as $u) {
                if (strcasecmp($u['email'], $data['email']) === 0 || strcasecmp($u['username'], $data['username']) === 0) {
                    return Http::response(['errorMessage' => 'User exists with same email'], 409);
                }
            }
            $id = (string) Str::uuid();
            $this->kcUsers[$id] = ['id' => $id] + $data;

            return Http::response('', 201, ['Location' => "http://kc-admin.test$base/users/$id"]);
        }
        if ($path === "$base/organizations") {
            return Http::response(array_values($this->orgs));
        }
        if (preg_match("#^$base/organizations/members/([^/]+)/organizations$#", $path, $m)) {
            return Http::response(array_values(array_filter($this->orgs, fn ($o) => in_array($m[1], $this->members[$o['alias']], true))));
        }
        if (preg_match("#^$base/organizations/([^/]+)/members$#", $path, $m) && $r->method() === 'POST') {
            $alias = collect($this->orgs)->firstWhere('id', $m[1])['alias'];
            $this->members[$alias][] = json_decode($r->body(), true);

            return Http::response('', 201);
        }
        if (preg_match("#^$base/roles/([^/]+)$#", $path, $m)) {
            return Http::response(['id' => 'role-'.$m[1], 'name' => $m[1]]);
        }
        if (preg_match("#^$base/users/([^/]+)/role-mappings/realm$#", $path, $m)) {
            $this->realmRoles[$m[1]] = array_column($r->data(), 'name');

            return Http::response('', 204);
        }

        return Http::response(['error' => 'unexpected '.$r->method().' '.$path], 500);
    }

    private function writes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET'
            && ! str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/token'))->all();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('keycloak:import-users', ['--company' => 'acme', '--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame([], $this->writes());
        $this->assertSame(0, User::whereNotNull('keycloak_id')->count());
    }

    public function test_import_creates_links_and_attaches_to_the_right_organization(): void
    {
        $this->artisan('keycloak:import-users', ['--company' => 'acme'])
            ->expectsOutputToContain('Résumé : créés=2, rattachés=1, conflits=1, erreurs=0')
            ->assertSuccessful();

        $alice = $this->seededUser('acme', 'alice@acme.test');
        $bob = $this->seededUser('acme', 'bob@acme.test');
        $eve = $this->seededUser('acme', 'eve@acme.test');

        // alice : rattachée au compte Keycloak existant (même sub).
        $this->assertSame(array_search('alice@acme.test', array_column($this->kcUsers, 'email', 'id'), true), $alice->keycloak_id);
        // bob, eve : créés, membres de l'Organization acme et d'elle seule.
        foreach ([$bob, $eve] as $u) {
            $this->assertNotNull($u->keycloak_id);
            $this->assertContains($u->keycloak_id, $this->members['acme']);
            $this->assertNotContains($u->keycloak_id, $this->members['globex']);
        }
        // Actions requises + VERIFY_EMAIL pour eve (email non vérifié).
        $this->assertSame(['UPDATE_PASSWORD', 'CONFIGURE_TOTP'], $this->kcUsers[$bob->keycloak_id]['requiredActions']);
        $this->assertSame(['UPDATE_PASSWORD', 'CONFIGURE_TOTP', 'VERIFY_EMAIL'], $this->kcUsers[$eve->keycloak_id]['requiredActions']);
        $this->assertTrue($this->kcUsers[$bob->keycloak_id]['credentials'][0]['temporary']);
        // bob est admin local -> rôle realm admin.
        $this->assertSame(['admin'], $this->realmRoles[$bob->keycloak_id]);
        // Autres sociétés non touchées.
        $this->assertSame(0, User::whereNotNull('keycloak_id')->where('company_id', '!=', $this->company('acme')->id)->count());
    }

    public function test_rerun_creates_no_duplicates(): void
    {
        $this->artisan('keycloak:import-users', ['--company' => 'acme'])->assertSuccessful();
        $kcCount = count($this->kcUsers);
        $subs = User::orderBy('id')->pluck('keycloak_id', 'id')->all();

        $this->artisan('keycloak:import-users', ['--company' => 'acme'])
            ->expectsOutputToContain('Résumé : créés=0, rattachés=0, conflits=1, erreurs=0')
            ->assertSuccessful();

        $this->assertSame($kcCount, count($this->kcUsers));
        $this->assertSame($subs, User::orderBy('id')->pluck('keycloak_id', 'id')->all());
    }

    public function test_rerun_after_partial_failure_links_the_existing_account(): void
    {
        // Simule un import interrompu : compte Keycloak créé mais sub non enregistré.
        $id = $this->addKcUser('bob@acme.test', 'bob@acme.test', null);

        $this->artisan('keycloak:import-users', ['--company' => 'acme'])->assertSuccessful();

        $this->assertSame($id, $this->seededUser('acme', 'bob@acme.test')->keycloak_id);
        $this->assertContains($id, $this->members['acme']);
    }

    public function test_email_shared_by_two_companies_is_reported_as_conflict_and_not_created(): void
    {
        $this->artisan('keycloak:import-users', ['--all' => true])
            ->expectsOutputToContain('Conflits (à traiter manuellement)')
            ->expectsOutputToContain('Email présent dans plusieurs sociétés')
            ->assertSuccessful();

        $this->assertNull($this->seededUser('acme', 'sam@shared.test')->keycloak_id);
        $this->assertNull($this->seededUser('globex', 'sam@shared.test')->keycloak_id);
        $this->assertEmpty(array_filter($this->kcUsers, fn ($u) => $u['email'] === 'sam@shared.test'));
    }

    public function test_existing_keycloak_account_in_another_organization_is_a_conflict(): void
    {
        $this->addKcUser('carol', 'carol@globex.test', 'acme');

        $this->artisan('keycloak:import-users', ['--company' => 'globex'])
            ->expectsOutputToContain('autre Organization (acme)')
            ->assertSuccessful();

        $this->assertNull($this->seededUser('globex', 'carol@globex.test')->keycloak_id);
    }

    public function test_limit_option_is_respected(): void
    {
        $this->artisan('keycloak:import-users', ['--company' => 'acme', '--limit' => 1])->assertSuccessful();

        $this->assertSame(1, User::whereNotNull('keycloak_id')->count());
    }

    public function test_password_never_appears_in_output_or_logs(): void
    {
        $logFile = storage_path('logs/laravel.log');
        $before = is_file($logFile) ? filesize($logFile) : 0;

        $this->artisan('keycloak:import-users', ['--all' => true])
            ->doesntExpectOutputToContain('Temp#Secret-NeverLogged')
            ->assertSuccessful();

        $newLogs = is_file($logFile) ? substr(file_get_contents($logFile), $before) : '';
        $this->assertStringNotContainsString('Temp#Secret-NeverLogged', $newLogs);
    }

    public function test_company_option_is_required(): void
    {
        $this->artisan('keycloak:import-users')->assertFailed();
        $this->artisan('keycloak:import-users', ['--company' => 'nope'])->assertFailed();
    }
}
