<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. order_adjustments ──────────────────────────────────────────────
        Schema::create('order_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                                // charge|discount|coupon
            $table->string('label');                                   // display name / coupon code snapshot
            $table->decimal('amount', 12, 2);                          // always >= 0; sign implied by type
            $table->unsignedBigInteger('coupon_id')->nullable();       // set only for type=coupon; soft ref (nullOnDelete managed at app layer)
            $table->string('source', 32);                              // checkout|admin|system|backfill
            $table->smallInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Allow multiple NULLs (charge/discount lines); prevent same coupon_id twice per order.
            // MySQL treats NULLs as distinct in unique indexes.
            $table->unique(['order_id', 'coupon_id']);
            $table->index(['order_id', 'type']);
            $table->index(['order_id', 'sort_order']);
            $table->index('coupon_id');
        });

        // ── 2. order_adjustment_logs (append-only audit) ──────────────────────
        Schema::create('order_adjustment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_adjustment_id')->nullable(); // snapshot ref; no FK (line may be deleted)
            $table->string('action', 32);                 // created|updated|deleted|replaced_set|backfilled
            $table->string('type', 16)->nullable();       // snapshot of line type
            $table->string('label')->nullable();           // snapshot of line label
            $table->string('field', 64)->nullable();       // delivery_charge|courier_charge|adjustment (for non-adjustment money changes)
            $table->string('phase', 32)->nullable();       // dispatch|webhook|tracking|delivered|cancelled|manual|checkout|admin_edit
            $table->unsignedBigInteger('source_courier_data_id')->nullable(); // ref to courier_data.id when phase is API-driven
            $table->decimal('amount_before', 12, 2)->nullable();
            $table->decimal('amount_after', 12, 2)->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();       // snapshot
            $table->json('meta_before')->nullable();
            $table->json('meta_after')->nullable();
            $table->decimal('order_charge_before', 12, 2)->nullable();
            $table->decimal('order_charge_after', 12, 2)->nullable();
            $table->decimal('order_discount_before', 12, 2)->nullable();
            $table->decimal('order_discount_after', 12, 2)->nullable();
            $table->decimal('order_total_before', 12, 2)->nullable();
            $table->decimal('order_total_after', 12, 2)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();             // append-only; no updated_at

            $table->index(['order_id', 'created_at']);
            $table->index('order_adjustment_id');
        });

        // ── 3. orders: add courier_charge ─────────────────────────────────────
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('courier_charge', 12, 2)->default(0)->after('charge');
        });

        // ── 4. products: add max_discount ─────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('max_discount', 12, 2)->nullable()->after('commission');
        });

        // ── 5. order_products: snapshot max_discount ──────────────────────────
        Schema::table('order_products', function (Blueprint $table) {
            $table->decimal('max_discount', 12, 2)->nullable()->after('commission_earned');
        });

        // ── 6. payment_transactions: additional columns ───────────────────────
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_transactions', 'kind')) {
                $table->string('kind', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('payment_transactions', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('kind');
            }
            if (! Schema::hasColumn('payment_transactions', 'payment_method_id')) {
                $table->foreignId('payment_method_id')
                    ->nullable()
                    ->after('paid_at')
                    ->constrained('payment_methods')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('payment_transactions', 'external_id')) {
                $table->string('external_id')->nullable()->after('payment_method_id');
            }
        });

        // Unique gateway trx id per method when external_id is set (MySQL allows multiple NULLs).
        if (Schema::hasColumn('payment_transactions', 'external_id')) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexNames = method_exists($sm, 'getIndexes')
                ? collect($sm->getIndexes('payment_transactions'))->pluck('name')->all()
                : [];

            if (! in_array('payment_transactions_method_external_id_unique', $indexNames, true)) {
                Schema::table('payment_transactions', function (Blueprint $table) {
                    $table->unique(['method', 'external_id'], 'payment_transactions_method_external_id_unique');
                });
            }
        }

        // ── 7. Seed payment_methods (insert if not exists by code) ────────────
        DB::table('payment_methods')->insertOrIgnore([
            ['name' => 'Cash on Delivery', 'code' => 'cod',   'charge' => 0, 'is_active' => true, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'bKash',            'code' => 'bkash', 'charge' => 0, 'is_active' => true, 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Nagad',            'code' => 'nagad', 'charge' => 0, 'is_active' => true, 'display_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cash',             'code' => 'cash',  'charge' => 0, 'is_active' => true, 'display_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bank Transfer',    'code' => 'bank',  'charge' => 0, 'is_active' => true, 'display_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── 8. Backfill order_adjustments from legacy scalars ─────────────────
        // Use a PHP timestamp so SQLite (tests) and MySQL both accept the SQL.
        $now = now()->format('Y-m-d H:i:s');

        // charge > 0 → charge adjustment line
        DB::statement("
            INSERT INTO order_adjustments
                (order_id, type, label, amount, source, sort_order, created_at, updated_at)
            SELECT id, 'charge', 'Charge', charge, 'backfill', 10, '{$now}', '{$now}'
            FROM orders
            WHERE charge > 0
        ");

        // discount > 0 with coupon → coupon adjustment line (coupon_id set)
        DB::statement("
            INSERT INTO order_adjustments
                (order_id, type, label, amount, coupon_id, source, sort_order, created_at, updated_at)
            SELECT o.id, 'coupon', COALESCE(c.code, 'Coupon'), o.discount, o.coupon_id, 'backfill', 20, '{$now}', '{$now}'
            FROM orders o
            LEFT JOIN coupons c ON c.id = o.coupon_id
            WHERE o.discount > 0 AND o.coupon_id IS NOT NULL
        ");

        // discount > 0 without coupon → discount adjustment line
        DB::statement("
            INSERT INTO order_adjustments
                (order_id, type, label, amount, source, sort_order, created_at, updated_at)
            SELECT id, 'discount', 'Discount', discount, 'backfill', 20, '{$now}', '{$now}'
            FROM orders
            WHERE discount > 0 AND coupon_id IS NULL
        ");

        // ── 9. Backfill order_adjustment_logs ─────────────────────────────────
        DB::statement("
            INSERT INTO order_adjustment_logs
                (order_id, order_adjustment_id, action, type, label,
                 amount_after, order_charge_after, order_discount_after, order_total_after,
                 note, created_at)
            SELECT
                oa.order_id,
                oa.id,
                'backfilled',
                oa.type,
                oa.label,
                oa.amount,
                o.charge,
                o.discount,
                o.total,
                'Backfilled from legacy scalar data',
                '{$now}'
            FROM order_adjustments oa
            JOIN orders o ON o.id = oa.order_id
            WHERE oa.source = 'backfill'
        ");
    }

    public function down(): void
    {
        // Remove backfilled logs first
        DB::statement("
            DELETE FROM order_adjustment_logs
            WHERE order_adjustment_id IN (
                SELECT id FROM order_adjustments WHERE source = 'backfill'
            )
        ");

        Schema::dropIfExists('order_adjustment_logs');
        Schema::dropIfExists('order_adjustments');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('courier_charge');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('max_discount');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn('max_discount');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('payment_transactions', 'payment_method_id')) {
                $table->dropForeign(['payment_method_id']);
            }
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['kind', 'paid_at', 'external_id', 'payment_method_id'],
                fn (string $col) => Schema::hasColumn('payment_transactions', $col),
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        DB::table('payment_methods')
            ->whereIn('code', ['cod', 'bkash', 'nagad', 'cash', 'bank'])
            ->delete();
    }
};
