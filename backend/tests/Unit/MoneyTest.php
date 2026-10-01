<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{string|int|float, int}> */
    public static function paisaProvider(): array
    {
        return [
            'whole taka string' => ['800', 80000],
            'two decimals' => ['1500.50', 150050],
            'one decimal' => ['1500.5', 150050],
            'decimal string' => ['0.07', 7],
            'int' => [12, 1200],
            'float' => [19.99, 1999],
            'float that is imprecise as a binary' => [0.1 + 0.2, 30],
            'rounds a third decimal half up' => ['0.005', 1],
            'drops a third decimal below half' => ['0.004', 0],
            'negative' => ['-2.50', -250],
            'surrounding spaces' => [' 3.10 ', 310],
            'large' => ['99999999.99', 9999999999],
        ];
    }

    #[DataProvider('paisaProvider')]
    public function test_to_paisa(string|int|float $amount, int $expected): void
    {
        $this->assertSame($expected, Money::toPaisa($amount));
    }

    public function test_to_paisa_refuses_text(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toPaisa('12abc');
    }

    public function test_from_paisa_is_a_decimal_2_string(): void
    {
        $this->assertSame('1500.50', Money::fromPaisa(150050));
        $this->assertSame('0.07', Money::fromPaisa(7));
        $this->assertSame('0.00', Money::fromPaisa(0));
        $this->assertSame('800.00', Money::fromPaisa(80000));
        $this->assertSame('-2.50', Money::fromPaisa(-250));
    }

    public function test_percent_of_rounds_half_up_to_the_paisa(): void
    {
        // 33.33% of ৳1000.00 is ৳333.30.
        $this->assertSame(33330, Money::percentOf(100000, '33.33'));
        $this->assertSame(40000, Money::percentOf(80000, '50'));
        $this->assertSame(80000, Money::percentOf(80000, '100.00'));
        $this->assertSame(0, Money::percentOf(80000, '0'));
        // 12.5% of ৳0.01 is 0.125 paisa: rounds to 0; 50% of 1 paisa is 0.5: rounds up to 1.
        $this->assertSame(0, Money::percentOf(1, '12.5'));
        $this->assertSame(1, Money::percentOf(1, '50'));
        // 15% of ৳33.33 = 4.9995 -> ৳5.00.
        $this->assertSame(500, Money::percentOf(3333, '15'));
    }

    public function test_parts_that_add_up_stay_exact(): void
    {
        // Three payments of 0.10 never drift the way float arithmetic would.
        $total = Money::toPaisa('0.10') * 3;

        $this->assertSame('0.30', Money::fromPaisa($total));
        $this->assertSame(30, Money::toPaisa(0.1 + 0.2));
    }

    public function test_display_groups_thousands_and_writes_bangla_digits(): void
    {
        $this->assertSame('৳১,৫০০.৫০', Money::display('1500.5'));
        $this->assertSame('৳1,500.50', Money::display('1500.5', false));
        $this->assertSame('৳০.০০', Money::display('0'));
        $this->assertSame('৳১,২৩৪,৫৬৭.০০', Money::display('1234567'));
    }
}
