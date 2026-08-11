<?php

namespace Tests\Unit;

use App\Services\LegacyImport\LegacyImporter;
use ReflectionMethod;
use Tests\TestCase;

class LegacyImporterPaymentStatusTest extends TestCase
{
    public function test_delivered_with_collected_and_due_equals_total_is_paid(): void
    {
        $this->assertSame('paid', $this->resolvePaymentStatus(0, 1500, 1500, 1500, 'delivered'));
    }

    public function test_partial_collection_is_partial(): void
    {
        $this->assertSame('partial', $this->resolvePaymentStatus(0, 800, 700, 1500, 'delivered'));
    }

    public function test_no_receipts_is_unpaid(): void
    {
        $this->assertSame('unpaid', $this->resolvePaymentStatus(0, 1500, 0, 1500, 'dispatched'));
    }

    private function resolvePaymentStatus(float $paid, float $due, float $collected, float $total, string $legacyStatus): string
    {
        $method = new ReflectionMethod(LegacyImporter::class, 'paymentStatus');
        $method->setAccessible(true);

        return $method->invoke(
            new LegacyImporter,
            $paid,
            $due,
            $collected,
            $total,
            $legacyStatus,
        );
    }
}
