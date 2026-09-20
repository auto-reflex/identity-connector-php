<?php

use AutoReflex\IdentityConnector\Client\IdentityRejected;
use AutoReflex\IdentityConnector\Client\Vehicle;
use AutoReflex\IdentityConnector\Facades\Identity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\VehicleNote;

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

    Route::middleware(['identity.auth:profile', 'identity.profile'])->group(function (): void {
        $shown = fn (Vehicle $vehicle) => ['id' => $vehicle->id, 'version' => $vehicle->version, 'groups' => $vehicle->groups, 'link' => $vehicle->link, 'role' => $vehicle->role, 'stale' => $vehicle->stale];
        $fields = fn (Request $request) => $request->query('fields') ? explode(',', (string) $request->query('fields')) : null;

        // Un refus d'Identity (`IdentityRejected`) est rendu tel quel par le fournisseur du workbench, corps compris.
        Route::get('/garage', fn (Request $request) => ['data' => array_map($shown, Identity::vehicles()->garage($fields($request))->vehicles)]);
        Route::get('/vehicles/{id}', fn (Request $request, string $id) => ['data' => ($vehicle = Identity::vehicles()->get($id, $fields($request))) === null ? abort(404) : $shown($vehicle)]);
        Route::post('/vehicles', fn (Request $request) => response()->json(['data' => $shown(Identity::vehicles()->create($request->json()->all()))], 201));
        Route::patch('/vehicles/{id}', fn (Request $request, string $id) => ['data' => $shown(Identity::vehicles()->update($id, $request->json('data', []), (int) $request->json('version'), (bool) $request->json('confirm_decrease')))]);
        Route::delete('/vehicles/{id}', function (string $id) {
            Identity::vehicles()->delete($id);

            return response()->noContent();
        });
        Route::put('/vehicles/{id}/link', fn (Request $request, string $id) => ['data' => Identity::vehicles()->link($id, (array) $request->json('groups'), (string) $request->json('visibility', 'private'), (bool) $request->json('share_usage'))]);
        Route::delete('/vehicles/{id}/link', function (string $id) {
            Identity::vehicles()->unlink($id);

            return response()->noContent();
        });
        Route::get('/vehicles/{id}/notes', fn (string $id) => ['count' => VehicleNote::query()->where('identity_vehicle_id', $id)->count()]);
        Route::post('/vehicles/{id}/notes', fn (Request $request, string $id) => VehicleNote::query()->create(['identity_vehicle_id' => $id, 'note' => (string) $request->json('note')]));
    });

    // Lecture d'un véhicule de tiers en service à service, comme la page publique d'un événement.
    Route::middleware('identity.auth')->get('/public/vehicles', function (Request $request) {
        $reader = (string) $request->query('reader', 'anonymous');

        return ['data' => array_map(fn (Vehicle $vehicle) => ['id' => $vehicle->id, 'groups' => $vehicle->groups, 'stale' => $vehicle->stale], Identity::vehicleClient()->publicMany(explode(',', (string) $request->query('ids')), $reader))];
    });

    // Sans profil : authentification seule.
    Route::middleware('identity.auth')->get('/whoami', fn (Request $request) => ['sub' => Identity::token()->subject]);
});
