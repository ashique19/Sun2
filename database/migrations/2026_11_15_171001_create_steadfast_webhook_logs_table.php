<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steadfast_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status');
            $table->string('notification_type')->nullable();
            $table->string('invoice')->nullable();
            $table->string('consignment_id')->nullable();
            $table->string('tracking_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('content_type')->nullable();
            $table->boolean('has_signature')->default(false);
            $table->longText('raw_body')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'id']);
            $table->index('outcome');
            $table->index('notification_type');
            $table->index('invoice');
            $table->index('consignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steadfast_webhook_logs');
    }
};
