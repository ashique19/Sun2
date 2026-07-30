<?php

namespace Tests\Unit;

use App\Services\Orders\OrderPackagingCost;
use PHPUnit\Framework\TestCase;

class OrderPackagingCostTest extends TestCase
{
    public function test_default_rates_by_product_quantity(): void
    {
        $service = new OrderPackagingCost;

        $this->assertSame(0.0, $service->defaultForQuantity(0));
        $this->assertSame(21.0, $service->defaultForQuantity(1));
        $this->assertSame(30.0, $service->defaultForQuantity(2));
        $this->assertSame(41.0, $service->defaultForQuantity(3));
        $this->assertSame(41.0, $service->defaultForQuantity(5));
    }
}
