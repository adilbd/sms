<?php

namespace Tests\Unit;

use App\Support\BanglaNumber;
use PHPUnit\Framework\TestCase;

class BanglaNumberTest extends TestCase
{
    public function test_it_writes_every_digit_in_bangla(): void
    {
        $this->assertSame('০১২৩৪৫৬৭৮৯', BanglaNumber::format('0123456789'));
    }

    public function test_it_keeps_decimals_and_other_characters(): void
    {
        $this->assertSame('৩.৬৪', BanglaNumber::format('3.64'));
        $this->assertSame('৩.৬৪', BanglaNumber::format(3.64));
        $this->assertSame('৮৫ / ১০০', BanglaNumber::format('85 / 100'));
        $this->assertSame('A+', BanglaNumber::format('A+'));
    }

    public function test_it_formats_integers_and_null(): void
    {
        $this->assertSame('২০২৬', BanglaNumber::format(2026));
        $this->assertSame('', BanglaNumber::format(null));
    }
}
