<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Renamed from legacy `payments` (which was accounts-payable / bills, NOT customer payments).
    public function up(): void
    {
        Schema::create('payables', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();       // legacy 0000-00-00 -> null
            $table->boolean('is_paid')->default(false);
            $table->mediumText('payment_details')->nullable();
            $table->string('attachment_file')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payables');
    }
};
