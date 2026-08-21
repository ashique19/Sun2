<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StorefrontContrastTokensTest extends TestCase
{
    /**
     * Relative luminance for sRGB hex (#RRGGBB).
     */
    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];

        $linear = array_map(function (float $c): float {
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    private function contrastRatio(string $fg, string $bg): float
    {
        $l1 = $this->luminance($fg);
        $l2 = $this->luminance($bg);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    #[Test]
    public function storefront_text_tokens_meet_wcag_aa_on_cream(): void
    {
        $cream = '#FAF6EF';

        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('#5C564C', $cream)); // muted
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('#7A6114', $cream)); // brand text
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('#FFFFFF', '#8F7218')); // CTA
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('#6B6459', $cream)); // secondary
    }
}
