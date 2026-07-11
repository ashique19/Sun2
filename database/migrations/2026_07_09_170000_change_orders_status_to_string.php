<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('status', 64)->default('new')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE orders MODIFY status VARCHAR(64) NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('status')->default('new')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE orders MODIFY status ENUM('new','confirmed','dispatched','delivered','returned','cancelled') NOT NULL DEFAULT 'new'");
    }
};
