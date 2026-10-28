<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            // CUSTOMER | SELLER | ADMIN
            $table->string('role', 16)->index();
            // ACTIVE | SUSPENDED | DELETED
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->timestamp('email_verified_at')->nullable();
            // Per l'admin creato dal seeder: obbligo di cambiare la password al primo accesso
            $table->boolean('must_change_password')->default(false);
            // Valorizzato quando l'account viene anonimizzato (cancellazione GDPR, roadmap R1)
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Codici monouso della 2FA via email
        Schema::create('login_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('expires_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('device', 120)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('login_challenges');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
