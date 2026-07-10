<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $coupons = [
            [
                'code' => 'SUN10',
                'type' => 'percent',
                'value' => 10,
                'min_order' => 500,
                'is_active' => true,
            ],
            [
                'code' => 'FLAT100',
                'type' => 'fixed',
                'value' => 100,
                'min_order' => 1000,
                'is_active' => true,
            ],
        ];

        foreach ($coupons as $coupon) {
            Coupon::query()->updateOrCreate(
                ['code' => $coupon['code']],
                $coupon,
            );
        }
    }
}
