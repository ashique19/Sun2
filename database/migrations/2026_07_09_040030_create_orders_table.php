<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();          // human ref (legacy id if present)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Buyer snapshot (kept denormalized, as in legacy)
            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('address');
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->enum('delivery_type', ['home', 'point'])->default('home');

            // Money (all decimal — legacy mixed int/double/varchar)
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->decimal('collected_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('due_amount', 12, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->string('payment_method', 32)->nullable();       // cod / bkash / ...

            // Fulfillment
            $table->enum('status', ['new', 'confirmed', 'dispatched', 'delivered', 'returned', 'cancelled'])->default('new');
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('courier_tracker')->nullable();
            $table->boolean('is_replacement')->default(false);
            $table->boolean('has_return')->default(false);
            $table->mediumText('admin_note')->nullable();
            $table->mediumText('customer_note')->nullable();

            // Dates (no ON UPDATE side effects; invalid legacy zero-dates map to null)
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('dispatch_date')->nullable();
            $table->timestamp('expected_delivery_date')->nullable();
            $table->timestamp('actual_delivery_date')->nullable();
            $table->timestamp('payment_date')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();

            $table->index('status');
            $table->index('phone');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
