<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            // max(0, prezzo - tariffa), in centesimi
            $table->unsignedInteger('amount_cents');
            $table->timestamp('scheduled_at')->index();
            // SCHEDULED | HELD | SENT | SKIPPED | REVERSED
            $table->string('status', 16)->default('SCHEDULED')->index();
            $table->string('stripe_transfer_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            // VISIBLE | REMOVED
            $table->string('status', 16)->default('VISIBLE');
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            // CLOSED_OR_NOT_DELIVERED | VALUE_BELOW_DECLARED | PLANTS_DAMAGED | NOT_AS_DECLARED
            $table->string('reason', 32);
            $table->text('description');
            // OPEN | ACCEPTED_FULL | ACCEPTED_PARTIAL | REJECTED
            $table->string('status', 16)->default('OPEN')->index();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('refund_cents')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('complaint_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('stripe_refund_id')->nullable();
            $table->timestamps();
        });

        // Eventi Stripe già elaborati: evita di eseguire due volte lo stesso webhook
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('payouts');
    }
};
