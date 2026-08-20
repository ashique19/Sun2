<?php

namespace Tests\Unit;

use App\Support\Bangla;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BanglaMoneyTest extends TestCase
{
    #[Test]
    public function it_converts_western_digits_to_bangla(): void
    {
        $this->assertSame('৫০০', Bangla::digits('500'));
        $this->assertSame('১,৫০০', Bangla::digits('1,500'));
    }

    #[Test]
    public function it_formats_whole_taka_amounts_with_bangla_digits(): void
    {
        $this->assertSame('৫০০', Bangla::money(500));
        $this->assertSame('১,৫০০', Bangla::money(1500));
        $this->assertSame('৯৮০', Bangla::money(980.00));
    }
}
