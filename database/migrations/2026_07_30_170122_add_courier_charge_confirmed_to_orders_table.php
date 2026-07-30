<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('courier_charge_confirmed_at')->nullable()->after('courier_charge');
            $table->foreignId('courier_charge_confirmed_by')
                ->nullable()
                ->after('courier_charge_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->index('courier_charge_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_charge_confirmed_by');
            $table->dropIndex(['courier_charge_confirmed_at']);
            $table->dropColumn('courier_charge_confirmed_at');
        });
    }
};
