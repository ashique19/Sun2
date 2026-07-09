<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Replaces legacy `gateways` metadata table.
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // Cash on Delivery / bKash / ...
            $table->string('code', 32)->unique();   // cod / bkash / nagad / card
            $table->decimal('charge', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
