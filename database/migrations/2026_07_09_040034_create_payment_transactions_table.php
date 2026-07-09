<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // NEW: actual customer payments (COD collection, bKash, gateway). Legacy `payments`
    // table was business payables, NOT customer payments.
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 32);           // cod / bkash / ...
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable(); // bKash trx id, etc.
            $table->string('status', 32)->default('pending');
            $table->json('meta')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
