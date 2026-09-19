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

    // Sans profil : authentification seule.
    Route::middleware('identity.auth')->get('/whoami', fn (Request $request) => ['sub' => Identity::token()->subject]);
});
