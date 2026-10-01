<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyPatternTest extends TestCase
{
    public function test_the_pattern_rejects_a_trailing_newline(): void
    {
        foreach (['100', '100.5', '100.00', '0.01'] as $ok) {
            $this->assertSame(1, preg_match(Money::MONEY_PATTERN, $ok), $ok);
        }

        foreach (["100.00\n", "100\n", '100.', '.5', '+5', '1.234'] as $bad) {
            $this->assertSame(0, preg_match(Money::MONEY_PATTERN, $bad), json_encode($bad));
        }
    }
}
