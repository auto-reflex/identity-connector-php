<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_user_id')->unique();
            $table->string('display_name')->nullable();
            $table->string('locale', 8)->nullable();
            $table->timestamp('product_suspended_at')->nullable();
            $table->timestamps();
        });
    }
};
