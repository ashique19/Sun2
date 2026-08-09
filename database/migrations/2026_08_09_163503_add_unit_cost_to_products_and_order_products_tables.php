<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('purchase_price');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('purchase_price');
        });

        // Existing rows: total cost equals main purchase_price until BOM is configured.
        DB::table('products')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('products')->where('id', $row->id)->update([
                    'unit_cost' => $row->purchase_price,
                ]);
            }
        });

        DB::table('order_products')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('order_products')->where('id', $row->id)->update([
                    'unit_cost' => $row->purchase_price,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
