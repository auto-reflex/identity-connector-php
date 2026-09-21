<?php

use AutoGteck\IdentityConnector\Facades\Identity;
use Illuminate\Support\Facades\Route;

/*
| Une application Blade minimale, comme Beacon : les routes de connexion sous /admin, avec un préfixe de noms (`admin.`) que le
| connecteur doit retrouver tout seul. Sert aux tests Pest et au smoke.
*/
Route::middleware('web')->prefix('admin')->name('admin.')->group(function (): void {
    Route::identityWeb();

    // La page de connexion de l'application : elle reçoit le code d'erreur en session flash.
    Route::get('login', fn () => response()->json(['error' => session('identity_error')]))->name('login');

    Route::middleware('identity.web:admin')->get('dashboard', fn () => response()->json([
        'name' => Identity::web()?->name,
        'roles' => Identity::web()?->roles,
        'has_admin' => Identity::hasRole('admin'),
        'token_subject' => Identity::token()?->subject,
    ]))->name('dashboard');

    Route::middleware('identity.web')->get('anyone', fn () => response()->json(['id' => Identity::web()?->id]))->name('anyone');
});
