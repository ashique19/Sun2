<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_charge_upto_5')->default(120)->after('unit_type');
            $table->unsignedSmallInteger('delivery_charge_over_5')->default(200)->after('delivery_charge_upto_5');
        });

        DB::table('areas')
            ->join('cities', 'areas.city_id', '=', 'cities.id')
            ->where('cities.slug', 'dhaka-dhaka')
            ->where('areas.unit_type', 'thana')
            ->update([
                'delivery_charge_upto_5' => 80,
                'delivery_charge_over_5' => 150,
            ]);
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn(['delivery_charge_upto_5', 'delivery_charge_over_5']);
        });
    }
};
