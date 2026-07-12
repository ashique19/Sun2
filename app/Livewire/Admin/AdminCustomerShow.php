<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Models\User;
use App\Livewire\Concerns\ManagesProductImagePreview;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AdminCustomerShow extends Component
{
    use ManagesProductImagePreview;
    use WithPagination;

    public User $customer;

    public string $displayName = '';

    public string $displayPhone = '';

    public string $displayAddress = '';

    public string $displayCity = '';

    public string $displayArea = '';

    public function mount(User $user): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($user->hasAnyRole(['admin', 'dev', 'moderator'])) {
            abort(404);
        }

        $this->customer = $user;
        $this->hydrateProfile();
    }

    public function title(): string
    {
        return $this->displayName !== '' ? $this->displayName : 'Customer';
    }

    public function render()
    {
        $orders = Order::query()
            ->where('user_id', $this->customer->id)
            ->with(['items:id,order_id,name,quantity,product_image,product_id', 'courier:id,name'])
            ->latest('placed_at')
            ->latest('id')
            ->simplePaginate(15);

        return view('livewire.admin.admin-customer-show', [
            'orders' => $orders,
        ])->title($this->title());
    }

    private function hydrateProfile(): void
    {
        $latestOrder = Order::query()
            ->where('user_id', $this->customer->id)
            ->latest('placed_at')
            ->latest('id')
            ->first(['id', 'name', 'phone', 'address', 'area', 'city']);

        $defaultAddress = $this->customer->addresses()
            ->with(['city:id,name', 'area:id,name'])
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();

        $this->displayName = (string) ($latestOrder?->name ?: $this->customer->name);
        $this->displayPhone = (string) ($latestOrder?->phone ?: $this->customer->phone);
        $this->displayAddress = (string) ($latestOrder?->address
            ?: $defaultAddress?->address
            ?: '');
        $this->displayCity = (string) ($latestOrder?->city
            ?: $defaultAddress?->city?->name
            ?: $defaultAddress?->city
            ?: '');
        $this->displayArea = (string) ($latestOrder?->area
            ?: $defaultAddress?->area?->name
            ?: $defaultAddress?->area
            ?: '');
    }
}
