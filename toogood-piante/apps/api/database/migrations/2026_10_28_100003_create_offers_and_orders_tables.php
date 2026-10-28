<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Importi sempre in centesimi (interi): niente numeri decimali per i soldi.
        Schema::create('box_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->date('offer_date');
            $table->foreignId('box_type_id')->constrained('box_types');
            $table->foreignId('category_id')->constrained('categories');
            // Codici di plant_attributes, es. ["INDOOR","TOXIC_PETS"]
            $table->json('attributes');
            $table->text('description');
            $table->unsignedInteger('list_value_cents');
            // Sconto intero in percentuale (es. 65)
            $table->unsignedTinyInteger('discount_pct');
            $table->unsignedInteger('price_cents');
            // Tariffa di servizio al momento della creazione (snapshot)
            $table->unsignedInteger('fee_cents');
            $table->unsignedSmallInteger('stock_total');
            $table->unsignedSmallInteger('stock_available');
            // ACTIVE | CLOSED
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['offer_date', 'seller_id', 'status']);
        });
        DB::statement('ALTER TABLE box_offers ADD CONSTRAINT chk_box_offers_stock CHECK (stock_available <= stock_total)');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            // PENDING_PAYMENT | PAID | PICKED_UP | NOT_PICKED_UP | IN_COMPLAINT
            // | REFUNDED_FULL | REFUNDED_PARTIAL | COMPLAINT_REJECTED | EXPIRED | PAYMENT_FAILED
            $table->string('status', 24)->index();
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('fee_total_cents');
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->timestamp('no_refund_accepted_at');
            // Fine della fascia di ritiro, in UTC (snapshot)
            $table->timestamp('pickup_window_end_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'order_date']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('box_offer_id')->constrained('box_offers')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('unit_fee_cents');
        });

        // Stock tenuto da parte durante il pagamento
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('box_offer_id')->constrained('box_offers')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->timestamp('expires_at')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pickup_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('jti', 64)->unique();
            $table->string('short_code', 12)->unique();
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_tokens');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('box_offers');
    }
};
