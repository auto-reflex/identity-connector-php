# AutoGteck Identity Connector

Package Laravel commun des APIs AutoGteck (AutoDonuts, AutoTrackly, AutoWorky, AutoReflex) pour s'appuyer sur le
service [Identity](../identity). Contrat : `AR-032` et `AR-052` à `AR-059` dans
`docs/ecosystem/DECISIONS.md`.

Ce qu'il fait :

- **authentifie** une requête par access token JWT d'Identity : signature RS256 (clés lues dans le JWKS, en cache),
  émetteur, audience de l'API, expiration, scopes — sans appeler Identity ;
- **crée le profil local** à la première requête d'une personne (lecture de `/userinfo`) ;
- **appelle l'API Identity** : organisations de la personne, véhicules (garage, lecture par lot, création, liens), statut d'un compte en service à service ;
- **reçoit les webhooks** signés d'Identity (suspension, réactivation et suppression de compte) et **accuse l'effacement** ;
- fournit un **Identity simulé** pour tester une API produit sans service Identity.

Il ne possède aucune table, aucun modèle, aucune route métier : le produit garde ses profils.

## Installation

Le dépôt est `auto-reflex/identity-connector-php` (public, versions en tags `vX.Y.Z`). Composer le lit par un dépôt `vcs`,
sans authentification. Distribution, CI et Docker : `docs/connecteur/distribution.md`.

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/auto-reflex/identity-connector-php.git" }],
"require": { "autogteck/identity-connector": "^0.1" }
```

Pour développer le connecteur et une API en même temps, remplacer localement le dépôt par un lien (sans commiter) :
`composer config repositories.identity-connector path ../../identity-connector && composer update autogteck/identity-connector`.

Guide pas à pas pour brancher un produit (API, mobile, vérification) : `docs/connecteur/` à la racine du monorepo.

Le service provider et la façade `Identity` sont découverts automatiquement. Publier la configuration si besoin :
`php artisan vendor:publish --tag=identity-connector-config`.

## Configuration (`.env` de l'API produit)

| Variable | Rôle |
| --- | --- |
| `IDENTITY_ISSUER` | URL publique d'Identity, **identique au claim `iss`** (obligatoire) |
| `IDENTITY_AUDIENCE` | audience de cette API : `autodonuts-api`, `autotrackly-api`, `pro-api` ou `map-api` (obligatoire) |
| `IDENTITY_URL` | URL de l'API si elle diffère de l'émetteur (réseau interne) |
| `IDENTITY_JWKS_URL` | par défaut `{issuer}/.well-known/jwks.json` ; HTTPS exigé hors local et testing |
| `IDENTITY_SERVICE_CLIENT_ID` / `_SECRET` | client de service (`autodonuts-api-service`…), pour `accountStatus()` |

Le reste (durées de cache, timeouts, tolérance d'horloge, chemin du webhook) est dans `config/identity-connector.php`.
Les secrets d'un client confidentiel (Map web) se déclarent dans `exchange.client_secrets`.

## Authentifier et charger le profil

```php
Route::middleware(['identity.auth:autodonuts:access', 'identity.profile'])->group(function () {
    Route::get('/me', fn (Request $request) => $request->user());   // le profil local
});
```

- `identity.auth[:scope,…]` : 401 (`WWW-Authenticate: Bearer error="invalid_token"`) si le token est absent, faux, expiré
  ou d'une autre audience (dont `identity-api`) ; 403 `insufficient_scope` s'il manque un scope ; **503** si aucune clé
  n'est disponible (Identity injoignable et cache vide). Le token vérifié est dans `Identity::token()`.
- `identity.profile` (après `identity.auth`) : charge le profil ; au premier appel, lit `/userinfo` avec le token
  reçu, refuse un email non vérifié (403), refuse un token de service (403), et **503** si Identity ne répond pas
  à ce moment-là. Une personne déjà connue n'a jamais besoin d'Identity pour être authentifiée. Le profil est l'utilisateur de la requête et du garde par défaut : `$request->user()`, `auth()->user()`, le `Gate` et les policies voient la même personne. Les deux middlewares s'exécutent avant `throttle` : un limiteur nommé peut donc être indexé sur `$request->user()`.

Le produit branche son modèle en implémentant `ProfileStore` :

```php
class EloquentProfileStore implements ProfileStore
{
    public function find(string $identityUserId): ?Authenticatable
    {
        return Profile::where('identity_user_id', $identityUserId)->first();
    }

    public function create(IdentityUser $user): Authenticatable
    {
        // `create`, pas `firstOrCreate` : l'unicité de `identity_user_id` arbitre les créations concurrentes.
        return Profile::create(['identity_user_id' => $user->id, 'display_name' => $user->name]);
    }
}
// AppServiceProvider::register() : $this->app->bind(ProfileStore::class, EloquentProfileStore::class);
```

Un profil qui implémente `SuspendableProfile` et se dit suspendu reçoit 403 : c'est la suspension **locale** du produit.

## Rôles d'équipe (AR-066)

Identity met les **rôles d'équipe** de la personne dans son token (claim `roles`), pour ce produit uniquement : un **super
administrateur** reçoit tous les rôles déclarés pour l'audience, sans qu'aucun produit n'ait rien à accorder ; les autres
membres de l'équipe reçoivent ceux qu'un super administrateur leur a accordés dans la console d'Identity. Le produit ne
stocke aucun rôle : il décide seulement **ce que chaque rôle permet**.

```php
Route::middleware(['identity.auth:autodonuts:access,autodonuts:admin', 'identity.profile', 'identity.role:admin'])
    ->prefix('admin')->group(function () { /* ... */ });

Identity::hasRole('admin');   // dans le code : la personne de la requête a-t-elle ce rôle ?
Identity::token()->roles;     // list<string>
```

- `identity.role:admin,moderator` : l'un **ou** l'autre. À placer après `identity.auth`, sinon 401.
- Sans le rôle : `403 {"error": "insufficient_role"}` (à convertir au format d'erreur du produit, comme les autres refus).
- Un retrait de rôle s'applique au plus tard à l'expiration du token en cours (15 minutes) ; un claim `roles` qui n'est pas
  une liste de noms est ignoré (aucun rôle).
- Un scope réservé (par exemple `autodonuts:admin`, demandé par le seul client du back-office) reste utile en plus du
  rôle : un token de l'application mobile d'un administrateur porte ses rôles, mais pas ce scope.
- Tests : `$identity->tokenFor($id, claims: ['roles' => ['admin']])`.

## Connexion d'une application web Blade (AR-072)

Pour un back-office **Laravel + Blade** (session serveur, pas d'API à jeton) : le connecteur fait la connexion OAuth
(code d'autorisation + **PKCE S256**, client **confidentiel**), garde la connexion, la renouvelle et la ferme. Rien de ceci
n'est lu tant qu'aucune route web n'est enregistrée : les APIs ne changent pas.

```dotenv
IDENTITY_ISSUER=https://identity.exemple                # URL publique d'Identity (là où le navigateur est envoyé)
IDENTITY_AUDIENCE=beacon-api                            # audience du produit
IDENTITY_WEB_CLIENT_ID=beacon-admin-web                 # client confidentiel déclaré dans Identity
IDENTITY_WEB_CLIENT_SECRET=…                            # secret du client : jamais dans l'image
IDENTITY_WEB_SCOPE=beacon:access                        # scope d'accès du produit, exigé dans le token
```

```php
// routes/web.php : DANS le groupe de l'application (préfixe, domaine, middleware `web`, préfixe de noms)
Route::prefix('admin')->name('admin.')->group(function () {
    Route::identityWeb();                                // GET auth/redirect, GET auth/callback, POST auth/logout
    Route::get('login', fn () => view('login'))->name('login');   // la page de connexion de l'application

    Route::middleware('identity.web:admin')->group(function () { /* pages protégées */ });
});

// config (AppServiceProvider::boot) : où renvoyer, où atterrir
config(['identity-connector.web.login_page' => 'admin.login', 'identity-connector.web.home' => 'admin.dashboard']);
```

- **Adresse de retour** : `route('…identity.web.callback')`, par exemple `https://app/admin/auth/callback`. Elle doit être
  déclarée **à l'identique** dans Identity (`redirect_uris` du client) ; `IDENTITY_WEB_REDIRECT_URI` la remplace.
- **`identity.web`** exige la connexion (sinon redirection vers `login_page`, ou 401 JSON pour une requête JSON) ;
  `identity.web:admin,moderator` exige en plus l'un des rôles (sinon **403**, à habiller dans l'application). La personne est
  lue par `Identity::web()` (`id`, `name`, `roles`), le token vérifié par `Identity::token()` et `Identity::hasRole('admin')`.
- **Erreurs de connexion** : retour sur `login_page` avec le code en session flash `identity_error` (`access_denied`,
  `invalid_state`, `unavailable`, `insufficient_scope`, `invalid_token`, `failed`, `session_expired`).
- **Déconnexion** : `POST` (CSRF) ; révoque le token chez Identity, oublie la connexion, et la connexion suivante envoie
  `prompt=login` (la session d'Identity reste ouverte : sans cela le retour serait automatique).
- **Renouvellement** : à l'approche de l'échéance du token d'accès (15 minutes), sous verrou de cache. Un rôle retiré ou un
  compte suspendu prend donc effet en 15 minutes au plus. Identity injoignable : la connexion vit jusqu'à l'échéance de son
  token, puis se ferme. Le magasin de cache (`IDENTITY_CACHE_STORE`, défaut : celui de l'application) doit gérer les verrous
  (`database`, `redis`, `file`, `array`).
- **Où sont les jetons** : chiffrés **dans le cache**, jamais dans la session (un refresh token ne sert qu'une fois : deux
  requêtes parallèles depuis une copie de session déconnecteraient la personne). La session ne porte qu'un identifiant.
  Activer `SESSION_ENCRYPT` reste recommandé.
- Tests : `$this->actingAsWebIdentity($identity, $userId, roles: ['admin'])` ouvre une connexion ; pour jouer le parcours,
  `$identity->web()->approve($locationVersIdentity, $userId)` rend l'adresse de retour à suivre (`refreshCalls()`,
  `revokedTokens()`, `roles()`).

## Appeler Identity

```php
use AutoGteck\IdentityConnector\Facades\Identity;
use AutoGteck\IdentityConnector\Client\{IdentityUnavailable, IdentityRejected};

try {
    $organizations = Identity::organizations();          // de la personne de la requête, avec son rôle
    $organization  = Identity::organization($id);        // détail et membres (sans email) ; null si inconnue ou étrangère
    $status = Identity::client()->accountStatus($userId); // service à service : exists, suspended, deletion, isActive()
} catch (IdentityUnavailable) {
    // panne, timeout, 5xx : dégrader l'affichage (AR-033), réessayer plus tard
} catch (IdentityRejected $e) {
    // refus (token révoqué, scope manquant…) : $e->status, $e->error
}
```

Le token `identity-api` s'obtient par échange RFC 8693 depuis le token produit et le token de service par
`client_credentials` ; les deux sont gardés en cache, **chiffrés**, jusqu'à 30 s avant leur expiration. Une seule
reprise sur les lectures, aucune sur les demandes de token. Aucun token n'est journalisé.

## Recevoir les webhooks

Le connecteur expose `POST /identity/webhooks` (chemin configurable). Depuis la 0.9 (AR-087), **aucun secret n'est à
configurer** : Identity signe chaque webhook avec sa clé (en-tête `Identity-Signature` = JWT RS256 de type
`identity-webhook+jwt`), et le connecteur le vérifie avec le JWKS qu'il télécharge déjà pour les access tokens. Il contrôle
l'émetteur, l'audience (un webhook destiné à un autre produit est refusé), la validité (5 min, plus la tolérance d'horloge)
et l'empreinte du corps brut. Il écarte les rejeux et déclenche des événements Laravel locaux. Si les clés d'Identity sont
injoignables, il répond 503 et Identity réessaie. L'adresse de réception se saisit dans la console d'Identity
(origine `api` de l'application).

```php
Event::listen(AutoGteck\IdentityConnector\Events\AccountSuspended::class, function ($event) {
    // $event->userId, $event->eventId, $event->occurredAt — traitement idempotent : livraison « au moins une fois »
});
```

Événements relayés : `AccountSuspended`, `AccountReinstated`, `AccountDeletionRequested`, `AccountDeletionCancelled`,
`AccountDeletionDue`, `OrganizationDeleted`, `OrganizationOwnerJoined` (AR-070) et `OrganizationUpdated` (AR-075) ; un type ou une version inconnus reçoivent 202 et sont ignorés. Si le traitement lève une exception, l'événement n'est pas marqué comme traité et Identity le renverra.
Une suspension locale du produit ne doit pas être levée par `AccountReinstated` (voir `workbench/app/Listeners`).

## Provisionner l'organisation d'un professionnel (AR-070)

Quand un produit valide lui-même une demande (la Map : l'équipe approuve une demande de référencement), il crée le compte et
l'organisation **à la validation**, sans échanger de mot de passe. Service à service, scope `organizations:provision` :

```php
$organization = Identity::client()->provisionOrganization(
    reference: (string) $demande->id,          // votre identifiant : rappeler avec la même référence ne crée rien de plus
    organizationName: $demande->company_name,
    legalSiret: $demande->siret,               // obligatoire (AR-075) : Identity le vérifie auprès de Sirene
    ownerUserId: $identityUserId,              // le compte connecté (AR-076) : propriétaire tout de suite, état `active`, aucun email
    // ownerEmail: $demande->email,            // OU une invitation (AR-070), à la place de ownerUserId : jamais les deux
    locale: 'fr',
);
$organization->state; // active (ownerUserId) ; pending (ownerEmail) : invitation envoyée, rappeler renvoie un nouveau lien
Identity::client()->provisionedOrganization($reference); // état : pending | expired | active, ownerUserId ; null si inconnue
```

**Avec `ownerUserId`** (AR-076) : le compte doit exister et avoir un email vérifié, sinon `ProvisioningRejected` (`->error` : `owner_unknown` ; `reference_conflict` si la référence
appartient à un autre propriétaire) ; la personne est propriétaire tout de suite et **aucun `OrganizationOwnerJoined` n'est émis**. **Avec `ownerEmail`**, l'organisation existe
tout de suite, sans membre. La personne devient propriétaire en acceptant l'invitation d'Identity (email
vérifié identique, AR-050). Vous en êtes prévenu par l'événement `OrganizationOwnerJoined` (`organizationId`, `userId`,
`reference`) : reliez alors votre donnée locale (`identity_organization_id`, propriétaire) et activez-la. En test :
`$identity->provisioned()` et `$identity->ownerJoins($reference, $userId)` (renvoie le webhook signé).

### Identité légale : le SIRET (AR-075, connecteur ≥ 0.6)

Identity vérifie le SIRET **une fois** (format et clé de Luhn, puis Sirene : établissement existant **et** actif) avant de créer quoi que ce soit ; rappeler avec
la même référence ne relance pas la vérification. Deux familles d'erreurs, à ne pas confondre :

```php
use AutoGteck\IdentityConnector\Client\{LegalIdentityRejected, RegistryUnavailable};

try {
    $organization = Identity::client()->provisionOrganization(/* … */);
} catch (LegalIdentityRejected $e) {
    // erreur de formulaire, à montrer : $e->error = siret_invalid | siret_not_found | siret_inactive | siret_taken
    // (siret_taken ne dit pas quelle organisation détient le SIRET)
} catch (RegistryUnavailable) {
    // Sirene ne répond pas, rien n'a été créé : « réessayez dans un instant » (RegistryUnavailable est une IdentityUnavailable)
}
```

`Identity::organization($id)` et `organizations()` exposent `kind` (`professional` | `association`) et `legal` (`null` sans SIRET, sinon `LegalIdentity` : `siret`,
`siren`, `legalName`, `form`, `address`, `nafCode`, `verified`, `verifiedAt`, `registry`). **Un produit qui facture copie `legal` sur chaque facture émise** ;
un produit qui publie (la Map) exige `verified`. L'événement `OrganizationUpdated` (`organizationId`, `changed: ['legal']`) prévient d'un SIRET posé, changé ou retiré, sans
porter le bloc légal : relire l'organisation, et ignorer celles que le produit ne connaît pas. En test : `$identity->legalBlock($siret)` avec
`organization(…, legal: …)`, `siretTaken()`, `siretNotFound()`, `siretInactive()`, `registryUnavailable()`, et `$identity->webhook('organization.updated', $id)`.

## Véhicules (AR-057 à AR-059)

Un véhicule est une fiche unique dans Identity ; le produit garde un **lien** et ses propres données, rattachées à `vehicle_id`.

```php
$garage  = Identity::vehicles()->garage(['identity', 'usage']);          // la personne connectée : garage et flottes
$vehicle = Identity::vehicles()->get($id, ['identity', 'specs']);       // null si absent ou illisible (404 indiscernables)
$created = Identity::vehicles()->create(['identity' => ['make' => 'Peugeot', 'model' => '205'], 'link' => ['groups' => ['identity', 'usage']]]);
$updated = Identity::vehicles()->update($id, ['usage' => ['mileage_km' => 183000]], $vehicle->version);   // IdentityRejected 412 si périmé
Identity::vehicles()->link($id, ['identity', 'specs'], 'public');       // consentement : les groupes que ce produit lira
Identity::vehicleClient()->publicMany($ids, reader: 'anonymous');      // tiers, en service à service, avec le lecteur déclaré
```

- **Groupes** : un `Vehicle` ne porte que les groupes accordés (`identity`, `specs`, `media`, `usage`, `sensitive`) : `$vehicle->has('usage')`.
  Une donnée absente n'est pas « vide », elle n'est pas accordée. `sensitive` (VIN, plaque) n'est renvoyé qu'à la personne propriétaire qui le demande
  (`fields`) avec le scope `vehicles:sensitive`, jamais à un tiers, et n'est **jamais mis en cache**.
- **Erreurs** : `IdentityRejected::$body` porte `current_version` (412, la fiche a changé : relire), `existing_vehicle_id` (409, doublon de VIN
  dans le garage), `errors` (422). Un kilométrage inférieur exige `confirmDecrease: true`.
- **Panne d'Identity** : les champs non sensibles sont gardés 60 s (`IDENTITY_VEHICLES_CACHE_SECONDS`) ; si Identity ne répond plus, une copie
  périmée (jusqu'à 1 h) est servie avec `$vehicle->stale === true`, sinon `IdentityUnavailable` : affichez un état dégradé. Une écriture invalide
  le cache de la personne.
- **Événements** : `VehicleDeleted` (véhicule supprimé) et `VehicleUnlinked` (lien retiré par le propriétaire ou le portail) : fermez ou anonymisez vos
  données locales rattachées à `vehicle_id` (voir `workbench/app/Listeners/CloseLocalVehicleData.php`).

## Suppression de compte (AR-055, AR-056)

Quand une personne supprime son compte AutoGteck, le produit reçoit trois événements, dans cet ordre :

1. `AccountDeletionRequested` (`scheduledFor`) : **verrouiller** le profil, sans rien effacer ; la personne a 30 jours
   pour changer d'avis.
2. `AccountDeletionCancelled` : elle s'est reconnectée, **déverrouiller**. (Retour possible à l'étape 1.)
3. `AccountDeletionDue` : **effacer maintenant** les données locales de ce compte (ou les anonymiser, selon les
   obligations du produit), puis **accuser** : `Identity::client()->acknowledgeDeletion($userId)`. Identity clôture
   quand tous les produits ont accusé, ou 14 jours après si l'un se tait.

```php
Event::listen(AccountDeletionDue::class, function ($event) {
    Profile::where('identity_user_id', $event->userId)->delete();            // effacement idempotent
    Identity::client()->acknowledgeDeletion($event->userId);                 // false s'il n'y a rien à accuser
});
```

Laissez remonter les exceptions (dont `IdentityUnavailable` à l'accusé) : le webhook répond alors 500 et Identity
renvoie l'événement, ce qui rejoue l'effacement sans effet. `OrganizationDeleted` demande de fermer l'extension locale qui
référençait cette organisation. Un compte dont `accountStatus()->deletion` n'est pas `null` n'est plus `isActive()`.

## Tester une API produit sans Identity

```php
use AutoGteck\IdentityConnector\Testing\FakesIdentity;

uses(FakesIdentity::class);

it('renvoie mon profil', function () {
    $identity = $this->fakeIdentity('autodonuts-api')->user('01J0…', name: 'Camille');

    $this->getJson('/api/me', ['Authorization' => 'Bearer '.$identity->tokenFor('01J0…')])->assertOk();
});
```

`FakeIdentity` simule le JWKS, `/userinfo`, l'échange de token, le token de service, l'API organisations et le statut
de compte, l'accusé de suppression (`deletion()`, `acknowledgedDeletions()`), le provisionnement d'organisations avec SIRET (`provisioned()`, `ownerJoins()`, `siretTaken()`, `registryUnavailable()`…), les véhicules (`vehicles()->add(...)`, mêmes groupes, visibilité et contrôle
de version que le vrai service, **sans validation des champs**), et permet de simuler une panne (`goDown()`),
une révocation, une rotation de clé et un webhook signé (`webhook()`), avec les mêmes formes et les mêmes refus que le vrai service.

## Développement

```bash
composer install
composer check                       # Pint, Larastan niveau 6, Pest
php scripts/smoke-connector.php      # contre un vrai Identity (voir ci-dessous)
```

Le dossier `workbench/` est l'**API de test** : une API produit minimale (profils SQLite, routes `/api/*`) qui sert aux
tests Pest et au smoke. `scripts/smoke-connector.php` lance Identity (`:8100`), son worker de file et le workbench
(`:8110`), puis vérifie de bout en bout : authentification, profil créé une fois, organisations via l'échange de
token, statut en service à service, rotation de clé, suspension et réactivation par webhook. Prérequis : le dépôt
`identity/` à côté, sa base PostgreSQL locale, Mailpit sur `:8028`. Il migre la base locale d'Identity et fixe le
secret du client de service `autodonuts-api-service` (valeurs de développement de `testbench.yaml`).

L'empreinte du corps signée dans les webhooks (`WebhookSignature::digest`, SHA-256 en base64url sans remplissage) est
calculée de la même façon par Identity (`App\Webhooks\WebhookSigner::digest`) : les deux dépôts doivent rester d'accord.
En test, `$identity->webhook(...)` renvoie un webhook signé par la clé du faux Identity, et `$identity->signedWebhook($body)`
signe un corps quelconque.
