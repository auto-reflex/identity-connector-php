# AutoReflex Identity Connector

Package Laravel commun des APIs AutoReflex (AutoDonuts, AutoTrackly, AutoReflexPro, Map) pour s'appuyer sur le
service [Identity](../identity). Contrat : `AR-032` et `AR-052` à `AR-054` dans
`docs/ecosystem/DECISIONS.md`.

Ce qu'il fait :

- **authentifie** une requête par access token JWT d'Identity : signature RS256 (clés lues dans le JWKS, en cache),
  émetteur, audience de l'API, expiration, scopes — sans appeler Identity ;
- **crée le profil local** à la première requête d'une personne (lecture de `/userinfo`) ;
- **appelle l'API Identity** : organisations de la personne, statut d'un compte en service à service ;
- **reçoit les webhooks** signés d'Identity (suspension et réactivation d'un compte) ;
- fournit un **Identity simulé** pour tester une API produit sans service Identity.

Il ne possède aucune table, aucun modèle, aucune route métier : le produit garde ses profils.

## Installation

Pas encore de dépôt privé : en développement, un dépôt Composer de type `path`.

```json
"repositories": [{ "type": "path", "url": "../identity-connector" }],
"require": { "autoreflex/identity-connector": "@dev" }
```

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
| `IDENTITY_WEBHOOK_SECRET` | secret du webhook (32 caractères minimum), `IDENTITY_WEBHOOK_PREVIOUS_SECRET` pendant une rotation |

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
  à ce moment-là. Une personne déjà connue n'a jamais besoin d'Identity pour être authentifiée.

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

## Appeler Identity

```php
use AutoReflex\IdentityConnector\Facades\Identity;
use AutoReflex\IdentityConnector\Client\{IdentityUnavailable, IdentityRejected};

try {
    $organizations = Identity::organizations();          // de la personne de la requête, avec son rôle
    $organization  = Identity::organization($id);        // détail et membres (sans email) ; null si inconnue ou étrangère
    $status = Identity::client()->accountStatus($userId); // service à service : exists, suspended, isActive()
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

Le connecteur expose `POST /identity/webhooks` (chemin configurable). Il vérifie la signature sur le corps brut,
l'horodatage (±5 min), écarte les rejeux et déclenche des événements Laravel locaux :

```php
Event::listen(AutoReflex\IdentityConnector\Events\AccountSuspended::class, function ($event) {
    // $event->userId, $event->eventId, $event->occurredAt — traitement idempotent : livraison « au moins une fois »
});
```

`AccountSuspended` et `AccountReinstated` existent aujourd'hui ; un type ou une version inconnus reçoivent 202 et sont
ignorés. Si le traitement lève une exception, l'événement n'est pas marqué comme traité et Identity le renverra.
Une suspension locale du produit ne doit pas être levée par `AccountReinstated` (voir `workbench/app/Listeners`).

## Tester une API produit sans Identity

```php
use AutoReflex\IdentityConnector\Testing\FakesIdentity;

uses(FakesIdentity::class);

it('renvoie mon profil', function () {
    $identity = $this->fakeIdentity('autodonuts-api')->user('01J0…', name: 'Camille');

    $this->getJson('/api/me', ['Authorization' => 'Bearer '.$identity->tokenFor('01J0…')])->assertOk();
});
```

`FakeIdentity` simule le JWKS, `/userinfo`, l'échange de token, le token de service, l'API organisations et le statut
de compte, et permet de simuler une panne (`goDown()`), une révocation, une rotation de clé et un webhook signé
(`webhook()`), avec les mêmes formes et les mêmes refus que le vrai service.

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

Le vecteur de référence de la signature des webhooks (`tests/Unit/WebhookSignatureTest.php`) est le même que celui
d'Identity (`docs/openapi.yaml`) : les deux dépôts doivent rester d'accord.
