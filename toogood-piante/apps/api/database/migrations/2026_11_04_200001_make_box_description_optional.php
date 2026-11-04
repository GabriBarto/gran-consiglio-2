<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La descrizione generale del contenuto è facoltativa: senza testo resta vuota (NULL)
        Schema::table('box_offers', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Le box senza descrizione ricevono un testo segnaposto prima di tornare obbligatorio
        \Illuminate\Support\Facades\DB::table('box_offers')->whereNull('description')->update(['description' => '']);

        Schema::table('box_offers', function (Blueprint $table) {
            $table->text('description')->nullable(false)->change();
        });
    }
};
