# POC Keycloak multi-sociétés (OIDC + TOTP) — Laravel + Flutter

Chaîne validée : **Flutter → Keycloak (mot de passe + TOTP) → JWT (avec société) → Laravel (cloisonnement par société) → même `User` local qu'avec l'ancien login Sanctum.**

```
poc-keycloak/
├── docker-compose.yml          # Keycloak 26.7.5 + PostgreSQL 16
├── keycloak/realm-plateforme.json
├── api/                        # Laravel 12 (PHP 8.2)
├── mobile/                     # Flutter 3.44
└── scripts/                    # outils de DEV : obtenir/décoder un vrai jeton
```

> Toutes les valeurs sensibles du dépôt (mots de passe, secret `laravel-importer`, clés de découverte) sont des **valeurs de DEV** explicitement marquées. Aucun secret réel.

---

## 1. Prérequis

| Outil | Version testée | Remarque |
|---|---|---|
| Docker Desktop | 29.x | Keycloak + PostgreSQL |
| PHP | 8.2 (XAMPP) | extensions `pdo_sqlite`, `openssl`, `curl`, `mbstring` |
| Composer | 2.x | |
| Flutter | 3.44 / Dart 3.12 | émulateur Android |

**Laravel 12 et non 13** : Laravel 13 exige PHP ≥ 8.3, alors que le PHP disponible (XAMPP) est en 8.2. Passer à Laravel 13 dès que PHP 8.3+ est disponible ; le code n'utilise rien de spécifique à la version 12.

---

## 2. Démarrage

```bash
# 1) Keycloak (import automatique du realm "plateforme")
docker compose up -d
#   -> http://localhost:8080 (console admin : admin / dev-only-admin)

# 2) API Laravel
cd api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan test
php artisan serve --host=0.0.0.0 --port=8000

# 3) Flutter (émulateur Android lancé)
cd mobile
flutter pub get
flutter analyze && flutter test
flutter run
```

Réimporter le realm après modification de `realm-plateforme.json` : `docker compose down -v && docker compose up -d`. L'import utilise la stratégie `IGNORE_EXISTING` : un realm déjà présent n'est jamais écrasé.

### Comptes de test

| Utilisateur | Société | Rôle | Mot de passe Keycloak | Mot de passe legacy (Sanctum) |
|---|---|---|---|---|
| `alice` / alice@acme.test | acme | user | `Alice#2026` | `password` |
| `bob` / bob@acme.test | acme | admin | `Bob#2026` | `password` |
| `carol` / carol@globex.test | globex | user | `Carol#2026` | `password` |
| `dave` / dave@initech.test | initech | admin | `Dave#2026` | `password` |

Ces quatre comptes existent dans le realm (action requise `CONFIGURE_TOTP`). Les autres comptes legacy (`eve@acme.test`, `frank@globex.test`, `grace@initech.test`, `sam@shared.test`) n'existent qu'en local et se migrent avec `keycloak:import-users`. Les comptes créés par l'import reçoivent le mot de passe **temporaire** `KEYCLOAK_IMPORT_TEMP_PASSWORD` (`Temp#Import-2026` en DEV), à changer obligatoirement au premier login.

`sam@shared.test` existe dans **acme ET globex** : c'est le cas d'ambiguïté volontaire.

### Clés de découverte (mock du serveur X)

| Clé | Société |
|---|---|
| `ACME-7K2P-DEV` | acme |
| `GLOBEX-4M9Q-DEV` | globex |
| `INITECH-8R3T-DEV` | initech |

`GET /api/discover?key=ACME-7K2P-DEV` renvoie `{company_slug, api_url, keycloak_issuer, client_id, org_alias}`. Le endpoint est limité à 10 requêtes par minute et par IP. Une clé inconnue renvoie une 404 identique octet pour octet à n'importe quelle autre 404, avec comparaison en temps constant.

### Obtenir un vrai jeton sans émulateur

`scripts/kc-token.php` joue le flux navigateur complet (Authorization Code + PKCE S256 + mot de passe + TOTP). À la première connexion, il configure le TOTP et conserve le secret dans `scripts/.totp/` (ignoré par git).

```bash
php scripts/kc-token.php alice 'Alice#2026' projet-1 > /tmp/alice.json
php scripts/jwt-decode.php < /tmp/alice.json          # header + payload
TOKEN=$(php -r 'echo json_decode(file_get_contents("/tmp/alice.json"))->access_token;')
curl -s http://localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
```

---

## 3. Architecture et décisions

### Keycloak (`keycloak/realm-plateforme.json`)
- Realm unique `plateforme` ; un client public par projet (`projet-1`, `projet-2`) : Authorization Code + **PKCE S256**, *direct access grants* désactivé, redirect `com.example.pocapp://login-callback`, post-logout `com.example.pocapp://logout-callback`.
- **Organizations** (`organizationsEnabled`) : `acme`, `globex`, `initech`, chacune avec son domaine `*.test`. Les membres sont importés avec le realm.
- **TOTP obligatoire** : action requise `CONFIGURE_TOTP` par défaut, politique TOTP SHA1, 6 chiffres, 30 s, codes non réutilisables.
- Mappers sur chaque client : audience (`aud` contient le client), attribut utilisateur `api_url` → claim `api_url`. L'attribut `api_url` est déclaré dans le *user profile*, en lecture et écriture réservées à l'admin.
- Rôles realm `user` (inclus dans les rôles par défaut) et `admin` (= admin de SA société).
- Client confidentiel `laravel-importer` avec service account (voir l'écart sur `manage-realm` ci-dessous).

**Format du claim `organization` vérifié sur Keycloak 26.7.5** (jeton réel) :

```json
"organization": ["acme"],
"scope": "openid organization offline_access profile email"
```

- Le scope client `organization` (mapper `oidc-organization-membership-mapper`, multivalué) est **optionnel** et doit être demandé.
- Le scope `organization:<alias>` présélectionne la société. Si l'utilisateur n'en est pas membre, le jeton est émis **sans** claim `organization`, et l'API répond alors 403 `NO_COMPANY`.
- Sans scope organisation, le claim est absent : 403 `NO_COMPANY`.
- L'app Flutter demande `organization:<org_alias de la découverte>`. Le paramètre `kc_org` n'a pas été retenu : son effet n'a pas pu être vérifié sur 26.7.5, contrairement au scope.
- Laravel accepte aussi le format objet (`{"acme": {"id": "…"}}`), produit si le mapper est configuré avec les attributs ou l'id d'organisation.
- **Le fallback « attribut `company` + mapper » n'a pas été nécessaire.**

### Laravel (`api/`)

| Élément | Fichier |
|---|---|
| Société courante (par requête, fail-closed) | `app/Tenancy/TenantContext.php` |
| Global scope + `company_id` imposé à la création | `app/Tenancy/BelongsToCompany.php` |
| JWKS en cache 10 min, refresh borné si `kid` inconnu, 503 si injoignable | `app/Auth/Keycloak/JwksProvider.php` |
| Validation RS256 seul, `exp`/`nbf`, `iss` exact, `aud` + `azp` = `KEYCLOAK_CLIENT_ID`, `typ=Bearer` | `app/Auth/Keycloak/KeycloakTokenValidator.php` |
| Société + utilisateur (sub → email vérifié même société → JIT) | `app/Auth/Keycloak/KeycloakUserResolver.php` |
| `auth.keycloak` / `auth.any` / `role:admin` | `app/Http/Middleware/` |
| Import vers Keycloak | `app/Console/Commands/KeycloakImportUsers.php` |
| Config par environnement (même code pour tous les projets) | `config/keycloak.php`, `config/discovery.php` |

Routes :

| Route | Accès |
|---|---|
| `GET /api/health` | publique |
| `GET /api/discover?key=` | publique, 10/min |
| `POST /api/login`, `GET /api/legacy/me` | legacy Sanctum (inchangées) |
| `GET /api/me`, `GET|POST /api/notes`, `GET /api/notes/{id}`, `GET /api/projects`, `GET /api/projects/{id}` | `auth.any` |
| `GET /api/admin/users` | `auth.any` + `role:admin` |

Codes d'erreur JSON (`{"error": CODE, "message": …}`) :

| HTTP | Codes |
|---|---|
| 401 | `UNAUTHENTICATED`, `INVALID_TOKEN` |
| 403 | `NO_COMPANY`, `MULTIPLE_COMPANIES`, `COMPANY_MISMATCH`, `EMAIL_NOT_VERIFIED`, `ACCOUNT_CONFLICT`, `FORBIDDEN_ROLE` |
| 404 | `NOT_FOUND` (aussi pour une ressource d'une autre société) |
| 429 | `TOO_MANY_REQUESTS` |
| 503 | `AUTH_PROVIDER_UNAVAILABLE` |

Les deux codes 403 ajoutés par rapport au cahier des charges :
- `EMAIL_NOT_VERIFIED` : un compte local non lié porte cet email dans la société, mais l'email Keycloak n'est pas vérifié. Le rattachement est refusé, et la création aussi, puisqu'elle produirait un doublon.
- `ACCOUNT_CONFLICT` : l'email est déjà porté par un autre compte, déjà lié, dans la société.

**Ordre des middlewares** : les middlewares d'authentification et de société sont placés **avant** `SubstituteBindings` (`bootstrap/app.php`). Sans cela, le route model binding de `/notes/{note}` serait résolu avant que la société soit connue.

**Utilisateur multi-sociétés (évolution possible)** : aujourd'hui 403 `MULTIPLE_COMPANIES`. Pour le supporter, il faudrait :
1. que l'app choisisse la société (scope `organization:<alias>`, déjà en place côté Flutter) ;
2. que Laravel accepte un claim multiple s'il contient la société demandée par un en-tête dédié ;
3. remplacer `users.company_id` par une table pivot `company_user` (un `User` local par identité, plusieurs appartenances).

### Flutter (`mobile/`)
- `services/auth_service.dart` : `flutter_appauth` (code + PKCE), scopes `openid profile email offline_access organization:<alias>`, refresh avant expiration (marge de 30 s, *single-flight*), `end-session` + purge à la déconnexion. Avant chaque login ou refresh en échec, une sonde sur `/.well-known/openid-configuration` distingue « Keycloak injoignable » des autres erreurs.
- `services/api_client.dart` : Dio + intercepteur (jeton frais avant requête, sur 401 un refresh forcé et un seul nouvel essai), conversion des erreurs en messages lisibles.
- `services/secure_store.dart` : jetons et configuration dans `flutter_secure_storage`. La déconnexion purge les jetons ; « Changer de société » purge tout.
- `state/session.dart` (Riverpod 3) et les écrans `company_key_screen`, `login_screen`, `home_screen`.

---

## 4. Scénario de test manuel

Prérequis : `docker compose up -d`, `php artisan migrate:fresh --seed`, `php artisan serve --host=0.0.0.0 --port=8000`, émulateur Android.

1. **Import acme**
   ```bash
   php artisan keycloak:import-users --company=acme --dry-run   # rien n'est écrit
   php artisan keycloak:import-users --company=acme
   ```
   Résultat obtenu :
   - `eve` créée ;
   - `alice` et `bob` rattachés à leur compte Keycloak existant ;
   - `sam@shared.test` en **conflit** (email présent dans 2 sociétés).

   Contrôles : `keycloak_id` est rempli, et dans la console, *Organizations → acme → Members* liste alice, bob et eve. Relancer la commande ne crée aucun doublon.
2. **Alice** : dans l'app, saisir la clé `ACME-7K2P-DEV`, puis « Se connecter ». Keycloak demande le mot de passe `Alice#2026`, puis affiche le QR TOTP à scanner avec Google Authenticator, puis demande le code.
   - Variante « mot de passe à redéfinir » : se connecter avec `eve@acme.test` et le mot de passe temporaire. Keycloak impose un nouveau mot de passe, puis le TOTP, puis la vérification d'email (bloquante sans SMTP, voir pièges).
3. **`/api/me`** : `local_user_id` = 1, identique à la valeur renvoyée par `/api/legacy/me` avant migration. Société acme, `auth_method` = `keycloak`, anciennes notes acme visibles.
4. **Carol** : « Changer de société », saisir la clé `GLOBEX-4M9Q-DEV`, se connecter avec carol. Elle ne voit **aucune** donnée acme (notes uniquement `company_id` globex ; l'accès direct `/api/notes/1` renvoie 404).
5. **Sanctum en parallèle** :
   ```bash
   curl -s -X POST localhost:8000/api/login -H 'Content-Type: application/json' -d '{"email":"alice@acme.test","password":"password","company":"acme"}'
   ```
   Le jeton obtenu fonctionne sur `/api/me` (`auth_method` = `sanctum`, même `local_user_id`), sur `/api/notes` (mêmes notes) et sur `/api/legacy/me`.
6. **Refresh, déconnexion, reconnexion** : rester plus de 5 min (durée de vie du jeton d'accès) puis « Recharger » : le refresh est silencieux, sans nouveau login. « Se déconnecter » appelle l'end-session Keycloak puis purge les jetons. À la reconnexion, Keycloak demande le mot de passe puis le code TOTP.
7. **Arrêt de Keycloak** : `docker compose stop keycloak`.
   - L'app affiche « Service d'authentification indisponible » et ne présente aucune donnée.
   - L'API répond 503 `AUTH_PROVIDER_UNAVAILABLE` dès que le JWKS n'est plus en cache (vérifié avec `php artisan cache:clear` : réponse en 2,7 s).

   Redémarrer avec `docker compose start keycloak`.

`/api/admin/users` : 403 pour alice, 200 pour bob (les 4 utilisateurs acme uniquement). Vérifié sur jetons réels.

---

## 5. Problèmes connus / pièges

- **Issuer (`iss`) et 10.0.2.2** : l'émulateur Android joint l'hôte via `10.0.2.2`, pas `localhost`. Keycloak est donc lancé avec `KC_HOSTNAME=http://10.0.2.2:8080` (URL *frontend* fixe, d'où `iss` = `http://10.0.2.2:8080/realms/plateforme` partout), `KC_HOSTNAME_STRICT=false` et `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`.
  - Laravel, sur l'hôte, appelle le JWKS et l'Admin API via `localhost:8080` (`KEYCLOAK_JWKS_URL`, `KEYCLOAK_ADMIN_BASE_URL`), mais valide `iss` contre `KEYCLOAK_ISSUER` = `http://10.0.2.2:8080/...`. Vérifié : un jeton obtenu via localhost porte bien `iss` = `10.0.2.2`.
  - Conséquence : un navigateur **de l'hôte** ne peut pas finir un login, car les formulaires pointent vers `10.0.2.2`. Utiliser `scripts/kc-token.php`, qui réécrit l'hôte.
  - Alternative : `KC_HOSTNAME=http://localhost:8080` côté Keycloak, `adb reverse tcp:8080 tcp:8080` et `adb reverse tcp:8000 tcp:8000` côté émulateur, puis issuer et URLs en `localhost` partout.
  - Le simulateur iOS n'a pas accès à `10.0.2.2` : pour iOS, utiliser cette alternative (`localhost`).
- **Horloge / NTP** : `exp`, `nbf` et `iat` sont contrôlés avec une tolérance de `KEYCLOAK_LEEWAY` (30 s). Une dérive d'horloge de l'émulateur, de l'hôte ou du conteneur au-delà de cette tolérance produit des 401 « inexplicables », ou des codes TOTP refusés si c'est le téléphone qui dérive. Garder NTP actif partout.
- **Audience** : Keycloak ne met pas le client dans `aud` par défaut. Un *audience mapper* par client est indispensable. Laravel exige à la fois `aud` contenant `KEYCLOAK_CLIENT_ID` et `azp` = `KEYCLOAK_CLIENT_ID`. Un jeton `projet-1` est refusé par une API configurée en `projet-2` (testé).
- **Claim `organization`** : il n'est présent que si le scope `organization` (ou `organization:<alias>`) est demandé. Oublier ce scope donne 403 `NO_COMPANY`.
- **Rôles par défaut et import de realm** : déclarer `roles.realm` dans le JSON écrase les rôles par défaut. Il faut aussi déclarer `default-roles-plateforme` (avec `offline_access`), et l'ajouter aux utilisateurs importés avec des `realmRoles` explicites. Sinon : `Offline tokens not allowed`.
- **Écart de droits sur le service account `laravel-importer`** : sur Keycloak 26.7.5, il n'existe **aucun rôle dédié aux organisations**. Mesures faites :
  - avec seulement `manage-users`, `view-users`, `query-users` : 403 sur toutes les API Organizations ;
  - avec `view-realm` en plus : toujours 403 ;
  - `manage-realm` est donc **nécessaire** et a été ajouté. C'est un droit large.

  Atténuations recommandées :
  - n'activer le client que pendant les campagnes de migration ;
  - restreindre l'accès réseau à l'Admin API ;
  - faire une rotation du secret après chaque campagne ;
  - ou bien séparer l'étape « rattachement à l'organisation » et la faire exécuter par un opérateur.
- **`VERIFY_EMAIL` sans SMTP** : les comptes importés avec un email non vérifié (ex. `eve`) reçoivent `VERIFY_EMAIL`. Sans serveur SMTP configuré dans le realm, ils restent bloqués à cette étape. C'est voulu (l'email n'est pas une preuve d'identité). Configurer un SMTP fiable avant la migration réelle.
- **Mot de passe des comptes importés** : les hashs bcrypt ne sont pas importés. Le POC pose un mot de passe temporaire commun (`KEYCLOAK_IMPORT_TEMP_PASSWORD`), qui n'est jamais affiché ni journalisé. En production :
  - **soit** envoyer un email *execute-actions* (`UPDATE_PASSWORD`, `CONFIGURE_TOTP`) ;
  - **soit** installer l'extension communautaire `keycloak-bcrypt`, qui importe les hashs (`credentialData` algorithme `bcrypt`) pour que les utilisateurs gardent leur mot de passe. Une extension tierce est à auditer, et le TOTP reste à configurer.
- **Email partagé entre sociétés** : Keycloak impose un email unique par realm (`duplicateEmailsAllowed=false`). L'import ne choisit jamais silencieusement : les deux comptes sont listés « à traiter manuellement ». Options :
  1. corriger l'email de l'un des comptes (le plus propre) ;
  2. utiliser un alias d'email (`sam+globex@shared.test`) ;
  3. créer deux identités Keycloak avec des `username` distincts et un seul email réel (nécessite `duplicateEmailsAllowed=true`, déconseillé) ;
  4. à terme, une seule identité membre de deux Organizations (utilisateur multi-sociétés, hors périmètre).
- **Fenêtre JWKS en cache** : quand Keycloak s'arrête, les jetons **déjà émis** restent valides jusqu'à leur `exp` (5 min) tant que le JWKS est en cache (10 min). C'est inhérent aux JWT. Aucun **nouveau** jeton ni refresh n'est possible, et sans JWKS en cache l'API répond 503. Pour une coupure immédiate, réduire `accessTokenLifespan` ou ajouter de l'introspection.
- **Sanctum pendant une panne Keycloak** : le chemin legacy ne dépend pas de Keycloak et continue de fonctionner (vérifié). Ce n'est pas un mode dégradé : un jeton Keycloak n'est **jamais** retenté via Sanctum. Retirer Sanctum à la fin de la migration supprime ce chemin.
- **TOTP réutilisé** : `otpPolicyCodeReusable=false`, donc un même code ne passe qu'une fois par fenêtre de 30 s.
- **Windows / PowerShell 5.1** : `Invoke-RestMethod` ne déroule pas les tableaux JSON dans un pipeline. Affecter le résultat à une variable avant de filtrer.

---

## 6. À retirer après migration

- Routes `POST /api/login` et `GET /api/legacy/me`, ainsi que `LegacyAuthController`.
- Middleware `auth.any` (`AuthenticateAny`) : le remplacer par `auth.keycloak` (`EnsureValidKeycloakToken`) sur toutes les routes.
- `SetTenantFromUser` (`tenant.user`).
- Sanctum : package, table `personal_access_tokens`, trait `HasApiTokens`.
- Colonne `users.password` (et `remember_token`), via une migration dédiée, une fois tous les comptes rattachés (`keycloak_id` non nul partout).
- Client `laravel-importer` (désactiver puis supprimer), variables `KEYCLOAK_IMPORTER_*` et `KEYCLOAK_IMPORT_TEMP_PASSWORD`.
- Mock de découverte, s'il est remplacé par le vrai serveur X.

---

## 7. Passage en production

- **Keycloak HA** : au moins 2 nœuds derrière un load balancer, cache distribué (Infinispan, intégré au mode `start`), `start --optimized` avec une image buildée. Jamais `start-dev`.
- **PostgreSQL managé** (sauvegardes, PITR, haute disponibilité) dédié à Keycloak.
- **HTTPS partout** : `KC_HOSTNAME=https://auth.exemple.com`, `sslRequired=all`. Côté app, `--dart-define=ALLOW_INSECURE=false`, et suppression de `network_security_config.xml` et de `NSAllowsLocalNetworking`.
- **Keycloak managé ou auto-hébergé** :
  - managé (Red Hat build of Keycloak, Cloud-IAM, etc.) : moins d'exploitation (mises à jour, HA, sauvegardes), mais un coût et une dépendance fournisseur ;
  - auto-hébergé : plus de contrôle, mais il faut assurer les montées de version (cadence ~trimestrielle), la supervision et la sécurité.

  Pour ~3 projets, ~10 sociétés et quelques milliers d'utilisateurs, les deux options tiennent la charge sans difficulté. Le choix dépend surtout de la capacité d'exploitation de l'équipe.
- **Secrets** : secret du service account et identifiants admin dans un coffre (Vault, secrets du cloud), jamais dans `.env` versionné.
- **SMTP** fiable pour `VERIFY_EMAIL`, la réinitialisation de mot de passe et *execute-actions*.
- **Ajout d'une société** :
  1. créer l'Organization dans Keycloak (alias, domaine) ;
  2. créer la ligne `companies` avec `keycloak_org_alias` = alias ;
  3. créer une clé de découverte longue et aléatoire, pointant vers l'`api_url` et le `client_id` du projet ;
  4. migrer les comptes avec `keycloak:import-users --company=<slug>`.
- **Ajout d'un projet** :
  1. créer un nouveau client public dans Keycloak (copie de `projet-1` : PKCE S256, redirects, *audience mapper*, mapper `api_url`, scope optionnel `organization`) ;
  2. déployer le **même code Laravel** avec `KEYCLOAK_CLIENT_ID=<nouveau client>` ;
  3. référencer ce `client_id` dans la découverte.
- **SSO d'entreprise par société** (hors POC) : les Organizations Keycloak permettent de lier un Identity Provider (Azure AD, Google Workspace) à une organisation et de rediriger automatiquement selon le domaine de l'email. Le cloisonnement Laravel reste inchangé, puisqu'il repose sur le claim `organization`.
- **Supervision** : endpoint de santé Keycloak (`:9000/health`), alertes sur les 503 `AUTH_PROVIDER_UNAVAILABLE` côté API et sur les échecs de connexion (événements Keycloak).

---

## 8. Récapitulatif des vérifications

| Vérification | Résultat |
|---|---|
| `docker compose up` | realm `plateforme` importé sans action manuelle, 3 Organizations et leurs membres |
| Jeton réel (code + PKCE + mot de passe + TOTP) | `iss`=10.0.2.2, `aud`/`azp`=`projet-1`, `organization: ["acme"]`, `api_url`, rôles |
| `php artisan test` | **64 tests verts** (legacy, validation JWT, isolation, provisionnement, rattachement, cohabitation, rôles, découverte, import) |
| Migrations | `migrate:rollback --step=2` puis `migrate` OK |
| Import réel acme | 1 créé, 2 rattachés, 1 conflit, 0 erreur ; relance sans doublon |
| curl, jetons réels | même `local_user_id` via Sanctum et Keycloak, mêmes notes, admin 403/200, carol cloisonnée (404 sur note acme) |
| Keycloak arrêté | 503 `AUTH_PROVIDER_UNAVAILABLE` |
| `flutter analyze` / `flutter test` | aucune erreur / 15 tests verts |
