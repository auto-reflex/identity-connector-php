<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Donnée locale d'un produit rattachée à un véhicule d'Identity par `identity_vehicle_id` (AR-025) : jamais une copie de la fiche.
        Schema::create('vehicle_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_vehicle_id')->index();
            $table->string('note');
            $table->timestamps();
        });
    }
};
