<?php

use AutoReflex\IdentityConnector\Facades\Identity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function (): void {
    Route::middleware(['identity.auth:profile', 'identity.profile'])->get('/me', fn (Request $request) => [
        'id' => $request->user()->id,
        'identity_user_id' => $request->user()->identity_user_id,
        'display_name' => $request->user()->display_name,
    ]);

    // Ressource protégée par un scope que le token de test n'a pas par défaut.
    Route::middleware('identity.auth:vehicles:read')->get('/needs-scope', fn () => ['ok' => true]);

    Route::middleware(['identity.auth:profile', 'identity.profile'])->group(function (): void {
        Route::get('/organizations', fn () => ['data' => Identity::organizations()]);
        Route::get('/organizations/{id}', fn (string $id) => ['data' => Identity::organization($id) ?? abort(404)]);
    });

    // État d'un compte lu en service à service (client_credentials) : de quoi se réconcilier après un webhook manqué.
    Route::middleware('identity.auth')->get('/status/{id}', function (string $id) {
        $status = Identity::client()->accountStatus($id);

        return [
            'id' => $status->id,
            'exists' => $status->exists,
            'suspended' => $status->suspended,
            'deletion' => $status->deletion,
            'deletion_scheduled_for' => $status->deletionScheduledFor?->toIso8601String(),
        ];
    });

    // Sans profil : authentification seule.
    Route::middleware('identity.auth')->get('/whoami', fn (Request $request) => ['sub' => Identity::token()->subject]);
});
