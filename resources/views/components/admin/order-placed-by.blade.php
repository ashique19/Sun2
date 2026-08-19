@props(['order'])

@php($label = $order->placedByLabel())
@php($isCustomer = $label === 'Customer')

<p {{ $attributes->class([
    'text-xs',
    'font-semibold text-[#C9A227]' => $isCustomer,
    'text-[#8C8474]' => ! $isCustomer,
]) }}>
    Placed by {{ $label }}
</p>
