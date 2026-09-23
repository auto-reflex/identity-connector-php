<?php

return [

    /*
    | Émetteur (claim `iss`) des access tokens : l'URL publique d'Identity, sans slash final.
    */
    'issuer' => env('IDENTITY_ISSUER'),

    /*
    | Audience de CETTE API (claim `aud`), par exemple `autotrackly-api`. Un token destiné à une autre
    | audience, dont `identity-api`, est refusé.
    */
    'audience' => env('IDENTITY_AUDIENCE'),

    /*
    | URL de base de l'API d'Identity (`/userinfo`, `/oauth/token`, `/api/v1`). Par défaut, l'émetteur ;
    | à renseigner si Identity est joignable ailleurs (réseau interne) que par son URL publique.
    */
    'url' => env('IDENTITY_URL'),

    /*
    | Où lire les clés de vérification. Par défaut `{issuer}/.well-known/jwks.json`. HTTPS exigé hors
    | environnements local et testing.
    */
    'jwks_url' => env('IDENTITY_JWKS_URL'),

    /*
    | Marge d'horloge en secondes pour `exp` et `nbf`.
    */
    'leeway' => (int) env('IDENTITY_LEEWAY', 30),

    /*
    | Cache des clés (JWKS). `store` : magasin de cache Laravel, `null` = celui par défaut.
    | `jwks_stale_hours` : durée pendant laquelle les dernières clés servent si Identity est injoignable.
    | `jwks_reload_seconds` : intervalle minimal entre deux rechargements provoqués par un `kid` inconnu.
    */
    'cache' => [
        'store' => env('IDENTITY_CACHE_STORE'),
        'jwks_stale_hours' => (int) env('IDENTITY_JWKS_STALE_HOURS', 24),
        'jwks_reload_seconds' => (int) env('IDENTITY_JWKS_RELOAD_SECONDS', 60),
    ],

    /*
    | Accès de service à service (AR-035, `client_credentials`) : le client de service de cette API, tel
    | qu'enregistré dans Identity (`autotrackly-api-service`…). Sert à `Identity::accountStatus()`.
    */
    'service' => [
        'client_id' => env('IDENTITY_SERVICE_CLIENT_ID'),
        'client_secret' => env('IDENTITY_SERVICE_CLIENT_SECRET'),
    ],

    /*
    | Échange de token pour `identity-api` : un client public (application mobile) n'a pas de secret ; le
    | back-end d'un client confidentiel (ex. `autoreflex-map-web`) indique ici son secret, par `client_id`.
    */
    'exchange' => [
        'client_secrets' => [
            // 'autoreflex-map-web' => env('IDENTITY_MAP_WEB_SECRET'),
        ],
    ],

    /*
    | Réception des webhooks d'Identity (AR-053, AR-087). Signés par la clé d'Identity (JWKS) : aucun secret à configurer.
    | `tolerance` : écart d'horloge admis (secondes). `replay_days` : durée pendant laquelle un événement déjà traité est refusé.
    */
    'webhooks' => [
        'path' => env('IDENTITY_WEBHOOK_PATH', 'identity/webhooks'),
        'tolerance' => (int) env('IDENTITY_WEBHOOK_TOLERANCE', 300),
        'replay_days' => (int) env('IDENTITY_WEBHOOK_REPLAY_DAYS', 7),
    ],

    /*
    | Véhicules (AR-059) : cache court des lectures de champs NON sensibles, et durée pendant laquelle une copie périmée sert si
    | Identity ne répond plus (`stale_seconds`). Le groupe `sensitive` n'est jamais mis en cache. `cache_seconds` = 0 désactive.
    */
    'vehicles' => [
        'cache_seconds' => (int) env('IDENTITY_VEHICLES_CACHE_SECONDS', 60),
        'stale_seconds' => (int) env('IDENTITY_VEHICLES_STALE_SECONDS', 3600),
    ],

    /*
    | Connexion d'une application WEB (Blade, session serveur, AR-072). Inutile pour une API : rien de ceci n'est lu tant qu'aucune
    | route `Identity::webRoutes()` n'existe. Le client est CONFIDENTIEL (code d'autorisation + PKCE) ; son secret reste sur le serveur.
    |
    | `scope` : scope d'accès du produit (ex. `beacon:access`), exigé dans le token ; `profile` est ajouté pour le nom.
    | `redirect_uri` : adresse de retour ; par défaut celle de la route de rappel. Elle doit être déclarée à l'identique dans Identity.
    | `login_page` / `home` : nom de route ou chemin de la page de connexion de l'application (les erreurs y reviennent en session
    | flash `identity_error`) et de la page d'accueil après connexion. `ttl_minutes` : durée maximale d'une connexion sans
    | renouvellement. `refresh_margin` : secondes avant l'échéance du token d'accès où l'on renouvelle.
    */
    'web' => [
        'client_id' => env('IDENTITY_WEB_CLIENT_ID'),
        'client_secret' => env('IDENTITY_WEB_CLIENT_SECRET'),
        'scope' => env('IDENTITY_WEB_SCOPE'),
        'redirect_uri' => env('IDENTITY_WEB_REDIRECT_URI'),
        'login_page' => null,
        'home' => '/',
        'ttl_minutes' => (int) env('IDENTITY_WEB_TTL_MINUTES', 720),
        'refresh_margin' => (int) env('IDENTITY_WEB_REFRESH_MARGIN', 60),
    ],

    /*
    | Appels HTTP vers Identity : timeouts courts, pour dégrader plutôt qu'attendre (AR-033).
    */
    'http' => [
        'timeout' => (int) env('IDENTITY_HTTP_TIMEOUT', 3),
        'connect_timeout' => (int) env('IDENTITY_HTTP_CONNECT_TIMEOUT', 2),
    ],

];
