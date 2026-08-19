@props(['order'])

<p {{ $attributes->class([
    'text-xs admin-order-placed-by',
    'admin-order-placed-by--customer' => $order->isPlacedByStorefrontCustomer(),
]) }}>
    Placed by {{ $order->placedByLabel() }}
</p>
