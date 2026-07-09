<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();          // Home / Office
            $table->string('name');
            $table->string('phone', 32);
            $table->string('address');
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();          // district
            $table->string('postcode', 20)->nullable();
            $table->foreignId('country_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
