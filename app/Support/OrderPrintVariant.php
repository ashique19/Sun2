<?php

namespace App\Support;

enum OrderPrintVariant: string
{
    case CourierDetailed = 'courier-detailed';
    case CourierMinimal = 'courier-minimal';
    case CustomerDetailed = 'customer-detailed';
    case CustomerMinimal = 'customer-minimal';

    public function label(): string
    {
        return match ($this) {
            self::CourierDetailed => 'Print for courier (Detailed)',
            self::CourierMinimal => 'Print for courier (Minimal)',
            self::CustomerDetailed => 'Print invoice for customer (Detailed with product primary photo)',
            self::CustomerMinimal => 'Print invoice for customer (Minimal)',
        };
    }

    public function view(): string
    {
        return match ($this) {
            self::CourierDetailed => 'admin.order-print-courier-detailed',
            self::CourierMinimal => 'admin.order-print-label',
            self::CustomerDetailed, self::CustomerMinimal => 'admin.order-print-customer-invoice',
        };
    }

    public function includesProductPhotos(): bool
    {
        return $this === self::CustomerDetailed;
    }

    public function usesOtg(): bool
    {
        return $this === self::CourierMinimal;
    }
}
