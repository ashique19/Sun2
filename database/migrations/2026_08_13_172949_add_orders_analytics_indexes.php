<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexNames = method_exists($sm, 'getIndexes')
                ? collect($sm->getIndexes('orders'))->pluck('name')->all()
                : [];

            if (! in_array('orders_placed_at_index', $indexNames, true)) {
                $table->index('placed_at', 'orders_placed_at_index');
            }

            if (! in_array('orders_status_created_at_index', $indexNames, true)) {
                $table->index(['status', 'created_at'], 'orders_status_created_at_index');
            }

            if (! in_array('orders_actual_delivery_date_index', $indexNames, true)) {
                $table->index('actual_delivery_date', 'orders_actual_delivery_date_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexNames = method_exists($sm, 'getIndexes')
                ? collect($sm->getIndexes('orders'))->pluck('name')->all()
                : [];

            if (in_array('orders_placed_at_index', $indexNames, true)) {
                $table->dropIndex('orders_placed_at_index');
            }

            if (in_array('orders_status_created_at_index', $indexNames, true)) {
                $table->dropIndex('orders_status_created_at_index');
            }

            if (in_array('orders_actual_delivery_date_index', $indexNames, true)) {
                $table->dropIndex('orders_actual_delivery_date_index');
            }
        });
    }
};
