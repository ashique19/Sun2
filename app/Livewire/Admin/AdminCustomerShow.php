<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ManagesProductImagePreview;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminAccess;
use Illuminate\Validation\Rules\Password;
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

    public bool $resetPasswordModalOpen = false;

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $message = null;

    public ?string $error = null;

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

    public function openResetPasswordModal(): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->resetPasswordModalOpen = true;
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetValidation();
        $this->error = null;
        $this->js('document.body.classList.add("overflow-hidden")');
    }

    public function closeResetPasswordModal(): void
    {
        $this->resetPasswordModalOpen = false;
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetValidation();
        $this->js('document.body.classList.remove("overflow-hidden")');
    }

    public function resetPassword(): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->message = null;
        $this->error = null;

        $validated = $this->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->customer->update([
            'password' => $validated['password'],
        ]);

        $this->closeResetPasswordModal();
        $this->message = 'Password reset for '.$this->customer->name.'.';
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
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();

        $this->displayName = (string) ($latestOrder?->name ?: $this->customer->name);
        $this->displayPhone = (string) ($latestOrder?->phone ?: $this->customer->phone);
        $this->displayAddress = (string) ($latestOrder?->address
            ?: $defaultAddress?->address
            ?: '');
        // addresses.city / addresses.area are string columns that shadow the city()/area() relations.
        $this->displayCity = (string) ($latestOrder?->city
            ?: $defaultAddress?->city()->value('name')
            ?: $defaultAddress?->getAttribute('city')
            ?: '');
        $this->displayArea = (string) ($latestOrder?->area
            ?: $defaultAddress?->area()->value('name')
            ?: $defaultAddress?->getAttribute('area')
            ?: '');
    }
}
