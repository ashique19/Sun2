<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();               // pathao / steadfast / redx / carrybee
            $table->decimal('charge', 12, 2)->default(60);
            $table->decimal('osd_charge', 12, 2)->default(110);       // outside Dhaka
            $table->decimal('customer_charge', 12, 2)->default(80);
            $table->decimal('customer_osd_charge', 12, 2)->default(120);
            $table->decimal('cod_percentage', 8, 2)->default(1);
            $table->decimal('balance', 12, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
