<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dati fissi gestiti dai seeder (roadmap R6: gestione da pannello admin)
        Schema::create('box_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('plant_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            // LOCATION | TOXICITY | LIGHT | CARE
            $table->string('group', 16);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        // Parametri di business modificabili senza cambiare il codice (tariffa, sconto, tempi...)
        Schema::create('config_params', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('value');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_params');
        Schema::dropIfExists('plant_attributes');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('box_types');
    }
};
