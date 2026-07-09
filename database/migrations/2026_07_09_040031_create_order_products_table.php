<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');                 // snapshot
            $table->string('product_image')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);   // legacy `value`
            $table->boolean('to_be_returned')->default(false);
            $table->boolean('return_received')->default(false);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_products');
    }
};
