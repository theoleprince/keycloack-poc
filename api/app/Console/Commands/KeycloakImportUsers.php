<?php

namespace App\Console\Commands;

use App\Auth\Keycloak\KeycloakAdminClient;
use App\Auth\Keycloak\KeycloakConflictException;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Migration des comptes locaux vers Keycloak, société par société.
 *
 * - Ne traite que les users SANS keycloak_id (relançable, idempotente).
 * - Compte Keycloak déjà existant avec le même email -> "rattaché" (le sub est
 *   enregistré, l'appartenance à l'Organization est garantie), sauf s'il
 *   appartient à une autre société ou est déjà lié à un autre compte local.
 * - Email présent dans plusieurs sociétés en local -> CONFLIT, jamais de choix
 *   silencieux (Keycloak impose un email unique par realm).
 * - Aucun mot de passe n'est affiché ni journalisé. Les hashs bcrypt ne sont
 *   PAS importés (voir README : alternative keycloak-bcrypt).
 */
class KeycloakImportUsers extends Command
{
    protected $signature = 'keycloak:import-users
        {--company= : slug de la société à migrer}
        {--all : migrer toutes les sociétés}
        {--dry-run : n\'écrit rien (ni Keycloak, ni base locale)}
        {--limit= : nombre maximum d\'utilisateurs traités}';

    protected $description = 'Crée/rattache les utilisateurs locaux dans Keycloak et leur Organization';

    private array $report = ['created' => [], 'linked' => [], 'conflicts' => [], 'errors' => []];

    public function handle(KeycloakAdminClient $kc): int
    {
        $this->report = ['created' => [], 'linked' => [], 'conflicts' => [], 'errors' => []];

        $companies = $this->selectedCompanies();
        if ($companies === null) {
            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $duplicateEmails = $this->emailsSharedAcrossCompanies();

        if ($dryRun) {
            $this->warn('DRY-RUN : aucune écriture dans Keycloak ni en base.');
        }

        $query = User::whereNull('keycloak_id')
            ->whereIn('company_id', $companies->pluck('id'))
            ->with('company')
            ->orderBy('id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        foreach ($query->get() as $user) {
            try {
                $this->importOne($kc, $user, $dryRun, $duplicateEmails);
            } catch (Throwable $e) {
                $this->record('errors', $user, KeycloakAdminClient::describe($e));
            }
        }

        $this->printReport($dryRun);

        return $this->report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function importOne(KeycloakAdminClient $kc, User $user, bool $dryRun, array $duplicateEmails): void
    {
        $company = $user->company;
        $email = Str::lower((string) $user->email);

        if ($email === '') {
            $this->record('conflicts', $user, 'Pas d\'email local : à traiter manuellement.');

            return;
        }

        if (in_array($email, $duplicateEmails, true)) {
            $this->record('conflicts', $user, 'Email présent dans plusieurs sociétés (email unique par realm Keycloak) : à traiter manuellement.');

            return;
        }

        if (! $company->keycloak_org_alias || ! ($org = $kc->organizationByAlias($company->keycloak_org_alias))) {
            $this->record('errors', $user, "Organization Keycloak introuvable pour la société {$company->slug}.");

            return;
        }

        $existing = $kc->findUserByEmail($email) ?? $kc->findUserByUsername($email);

        if ($existing) {
            $this->linkExisting($kc, $user, $existing, $org, $dryRun);

            return;
        }

        if ($dryRun) {
            $this->record('created', $user, 'serait créé + ajouté à '.$org['alias']);

            return;
        }

        try {
            $sub = $kc->createUser($this->representation($user, $email));
        } catch (KeycloakConflictException) {
            $this->record('conflicts', $user, 'Username/email déjà pris dans Keycloak (409) : à traiter manuellement.');

            return;
        }

        // Le sub est enregistré tout de suite : si la suite échoue, la relance
        // retrouvera le compte par email et terminera le rattachement.
        $this->saveSub($user, $sub);
        $kc->addOrganizationMember($org['id'], $sub);
        if ($user->role === 'admin') {
            $kc->assignRealmRole($sub, 'admin');
        }

        $this->record('created', $user, 'créé + ajouté à '.$org['alias']);
    }

    private function linkExisting(KeycloakAdminClient $kc, User $user, array $existing, array $org, bool $dryRun): void
    {
        $other = User::where('keycloak_id', $existing['id'])->whereKeyNot($user->id)->first();
        if ($other) {
            $this->record('conflicts', $user, "Compte Keycloak déjà lié au user local #{$other->id} : à traiter manuellement.");

            return;
        }

        $aliases = $kc->userOrganizationAliases($existing['id']);
        $foreign = array_diff($aliases, [$org['alias']]);
        if ($foreign !== []) {
            $this->record('conflicts', $user, 'Compte Keycloak membre d\'une autre Organization ('.implode(', ', $foreign).') : à traiter manuellement.');

            return;
        }

        if ($dryRun) {
            $this->record('linked', $user, 'serait rattaché au compte Keycloak existant');

            return;
        }

        if (! in_array($org['alias'], $aliases, true)) {
            $kc->addOrganizationMember($org['id'], $existing['id']);
        }
        $this->saveSub($user, $existing['id']);

        $this->record('linked', $user, 'rattaché au compte Keycloak existant ('.$org['alias'].')');
    }

    private function representation(User $user, string $email): array
    {
        [$first, $last] = array_pad(explode(' ', trim($user->name), 2), 2, '');

        $actions = ['UPDATE_PASSWORD', 'CONFIGURE_TOTP'];
        if (! $user->email_verified_at) {
            $actions[] = 'VERIFY_EMAIL';
        }

        $rep = [
            'username' => $email,
            'email' => $email,
            'emailVerified' => $user->email_verified_at !== null,
            'enabled' => true,
            'firstName' => $first,
            'lastName' => $last,
            'attributes' => ['api_url' => [config('keycloak.admin.default_api_url')]],
            'requiredActions' => $actions,
        ];

        if ($temp = config('keycloak.admin.import_temp_password')) {
            $rep['credentials'] = [['type' => 'password', 'value' => $temp, 'temporary' => true]];
        }

        return $rep;
    }

    private function saveSub(User $user, string $sub): void
    {
        DB::transaction(function () use ($user, $sub) {
            User::whereKey($user->id)->whereNull('keycloak_id')->update(['keycloak_id' => $sub]);
        });
    }

    /** @return list<string> emails (minuscules) présents dans 2 sociétés ou plus */
    private function emailsSharedAcrossCompanies(): array
    {
        return User::query()
            ->whereNotNull('email')
            ->selectRaw('LOWER(email) as e')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(DISTINCT company_id) > 1')
            ->pluck('e')
            ->all();
    }

    private function selectedCompanies()
    {
        if ($this->option('all')) {
            return Company::orderBy('id')->get();
        }

        $slug = $this->option('company');
        if (! $slug) {
            $this->error('Préciser --company=<slug> ou --all.');

            return null;
        }

        $company = Company::where('slug', $slug)->first();
        if (! $company) {
            $this->error("Société inconnue : {$slug}");

            return null;
        }

        return collect([$company]);
    }

    private function record(string $bucket, User $user, string $detail): void
    {
        $this->report[$bucket][] = [$user->id, $user->company->slug, $user->email, $detail];
    }

    private function printReport(bool $dryRun): void
    {
        $labels = [
            'created' => $dryRun ? 'À créer' : 'Créés',
            'linked' => $dryRun ? 'À rattacher' : 'Rattachés',
            'conflicts' => 'Conflits (à traiter manuellement)',
            'errors' => 'Erreurs',
        ];

        foreach ($labels as $bucket => $label) {
            if ($this->report[$bucket] !== []) {
                $this->newLine();
                $this->info($label);
                $this->table(['user_id', 'société', 'email', 'détail'], $this->report[$bucket]);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Résumé : %s=%d, %s=%d, conflits=%d, erreurs=%d',
            Str::lower($labels['created']), count($this->report['created']),
            Str::lower($labels['linked']), count($this->report['linked']),
            count($this->report['conflicts']), count($this->report['errors']),
        ));
    }
}
