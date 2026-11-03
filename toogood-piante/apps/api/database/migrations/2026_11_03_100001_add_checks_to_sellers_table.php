<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            // Esito del controllo automatico della P.IVA, mostrato all'admin: VALID | UNAVAILABLE
            $table->string('vat_check_status', 16)->nullable()->after('vat_number');
            // Quando Stripe ha confermato che l'onboarding Connect è completo
            $table->timestamp('stripe_onboarded_at')->nullable()->after('stripe_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn(['vat_check_status', 'stripe_onboarded_at']);
        });
    }
};
