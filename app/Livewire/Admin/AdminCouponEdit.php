<?php

namespace App\Livewire\Admin;

use App\Models\Coupon;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminCouponEdit extends Component
{
    public ?Coupon $coupon = null;

    public string $code = '';

    public string $type = 'fixed';

    public string $value = '0';

    public string $min_order = '0';

    public string $starts_at = '';

    public string $ends_at = '';

    public string $usage_limit = '';

    public bool $is_active = true;

    public ?string $message = null;

    public function mount(?Coupon $coupon = null): void
    {
        if ($coupon?->exists) {
            $this->coupon = $coupon;
            $this->code = $coupon->code;
            $this->type = $coupon->type;
            $this->value = (string) (int) round((float) $coupon->value);
            $this->min_order = (string) (int) round((float) $coupon->min_order);
            $this->starts_at = $coupon->starts_at?->format('Y-m-d\TH:i') ?? '';
            $this->ends_at = $coupon->ends_at?->format('Y-m-d\TH:i') ?? '';
            $this->usage_limit = $coupon->usage_limit === null ? '' : (string) $coupon->usage_limit;
            $this->is_active = (bool) $coupon->is_active;
        }
    }

    public function title(): string
    {
        return $this->coupon ? 'Edit coupon '.$this->coupon->code : 'Create Coupon';
    }

    public function save(): void
    {
        $this->message = null;

        $codeUnique = $this->coupon
            ? 'unique:coupons,code,'.$this->coupon->id
            : 'unique:coupons,code';

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:40', $codeUnique],
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_order' => ['required', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        if ($validated['type'] === 'percent' && (float) $validated['value'] > 100) {
            $this->addError('value', 'Percent coupons cannot exceed 100.');

            return;
        }

        $payload = [
            'code' => strtoupper(trim($validated['code'])),
            'type' => $validated['type'],
            'value' => (float) $validated['value'],
            'min_order' => (float) $validated['min_order'],
            'starts_at' => $validated['starts_at'] !== '' && $validated['starts_at'] !== null
                ? Carbon::parse($validated['starts_at'])
                : null,
            'ends_at' => $validated['ends_at'] !== '' && $validated['ends_at'] !== null
                ? Carbon::parse($validated['ends_at'])
                : null,
            'usage_limit' => $validated['usage_limit'] !== '' && $validated['usage_limit'] !== null
                ? (int) $validated['usage_limit']
                : null,
            'is_active' => $validated['is_active'],
        ];

        if ($this->coupon) {
            $this->coupon->update($payload);
            $this->coupon = $this->coupon->fresh();
            $this->message = 'Coupon saved.';
        } else {
            $coupon = Coupon::query()->create($payload);
            $this->redirect(route('admin.coupons.edit', $coupon), navigate: true);
        }
    }

    public function delete(): void
    {
        if (! $this->coupon) {
            return;
        }

        $this->coupon->delete();
        $this->redirect(route('admin.coupons'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-coupon-edit')->title($this->title());
    }
}
