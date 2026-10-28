<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('vat_number', 16)->unique();
            $table->string('phone', 32);
            $table->string('address');
            $table->string('city')->index();
            // Coordinate in gradi decimali. La distanza si calcola con ST_Distance_Sphere(POINT(lng, lat), ...)
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->index(['latitude', 'longitude']);

            // MVP: un'unica fascia valida per tutti i giorni (orari per giorno: roadmap R2)
            $table->time('opens_at');
            $table->time('closes_at');
            $table->time('pickup_from');
            $table->time('pickup_to');

            $table->string('stripe_account_id')->nullable();
            // PENDING | APPROVED | REJECTED | SUSPENDED
            $table->string('verification_status', 16)->default('PENDING')->index();
            $table->text('verification_note')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            // Chiave dell'oggetto nello storage privato (S3)
            $table->string('storage_key');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_documents');
        Schema::dropIfExists('sellers');
    }
};
