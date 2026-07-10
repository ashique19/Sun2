<?php

namespace App\Livewire\Admin;

use App\Models\Coupon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Coupons')]
#[Layout('components.layouts.admin')]
class AdminCoupons extends Component
{
    public function delete(int $couponId): void
    {
        Coupon::query()->whereKey($couponId)->delete();
    }

    public function toggleActive(int $couponId): void
    {
        $coupon = Coupon::query()->findOrFail($couponId);
        $coupon->update(['is_active' => ! $coupon->is_active]);
    }

    public function render()
    {
        return view('livewire.admin.admin-coupons', [
            'coupons' => Coupon::query()
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->get(),
        ]);
    }
}
