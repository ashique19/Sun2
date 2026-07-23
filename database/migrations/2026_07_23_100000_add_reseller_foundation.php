<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $vendors = Role::query()->where('name', 'vendors')->where('guard_name', 'web')->first();
        if ($vendors) {
            $vendors->name = 'reseller';
            $vendors->save();
        } else {
            Role::findOrCreate('reseller', 'web');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('commission', 12, 2)->default(0)->after('purchase_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('reseller_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->decimal('base_price', 12, 2)->default(0)->after('quantity');
            $table->decimal('commission_rate', 12, 2)->default(0)->after('purchase_price');
            $table->decimal('commission_earned', 12, 2)->default(0)->after('commission_rate');
        });

        // Backfill base_price from existing unit price for historical lines.
        DB::table('order_products')->update([
            'base_price' => DB::raw('price'),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('reseller_balance', 12, 2)->default(0)->after('referral_balance');
        });

        Schema::create('reseller_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // commission | payout | adjustment | reversal
            $table->decimal('amount', 12, 2); // signed
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_wallet_entries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reseller_balance');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn(['base_price', 'commission_rate', 'commission_earned']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('commission');
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $reseller = Role::query()->where('name', 'reseller')->where('guard_name', 'web')->first();
        if ($reseller) {
            $reseller->name = 'vendors';
            $reseller->save();
        }
    }
};
