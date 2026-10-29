<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_match_candidates_include_local_and_880_forms(): void
    {
        $candidates = PhoneNumber::matchCandidates('01712345678');

        $this->assertContains('01712345678', $candidates);
        $this->assertContains('1712345678', $candidates);
        $this->assertContains('8801712345678', $candidates);
        $this->assertContains('+8801712345678', $candidates);
    }

    public function test_display_normalizes_880_prefix_to_local_mobile(): void
    {
        $this->assertSame('01712345678', PhoneNumber::display('8801712345678'));
        $this->assertSame('01712345678', PhoneNumber::display('+8801712345678'));
        $this->assertSame('1712345678', PhoneNumber::normalize('01712345678'));
        $this->assertSame('1712345678', PhoneNumber::normalize('8801712345678'));
    }

    public function test_legacy_user_id_suffix_is_the_same_mobile(): void
    {
        $this->assertSame('1866609878', PhoneNumber::normalize('01866609878-26841'));
        $this->assertSame('1866609878', PhoneNumber::normalize('01866609878-24973'));
        $this->assertSame('01866609878', PhoneNumber::display('01866609878-26841'));
        $this->assertTrue(PhoneNumber::isValidDisplayMobile(PhoneNumber::display('01866609878-24360')));
        $this->assertContains('01866609878-%', PhoneNumber::suffixLikePatterns('01866609878'));
    }
}
