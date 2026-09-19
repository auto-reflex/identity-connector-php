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
    */
    'cache' => [
        'store' => env('IDENTITY_CACHE_STORE'),
        'jwks_stale_hours' => (int) env('IDENTITY_JWKS_STALE_HOURS', 24),
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
    | Appels HTTP vers Identity : timeouts courts, pour dégrader plutôt qu'attendre (AR-033).
    */
    'http' => [
        'timeout' => (int) env('IDENTITY_HTTP_TIMEOUT', 3),
        'connect_timeout' => (int) env('IDENTITY_HTTP_CONNECT_TIMEOUT', 2),
    ],

];
