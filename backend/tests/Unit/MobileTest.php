<?php

namespace Tests\Unit;

use App\Support\Mobile;
use PHPUnit\Framework\TestCase;

class MobileTest extends TestCase
{
    public function test_normalizes_the_country_prefix_and_separators(): void
    {
        foreach (['01711111111', '+8801711111111', '8801711111111', ' 0171-111 1111 ', '+88 01711111111'] as $input) {
            $this->assertSame('01711111111', Mobile::normalize($input), $input);
        }
    }

    public function test_leaves_other_input_for_validation_to_reject(): void
    {
        $this->assertNull(Mobile::normalize(null));
        $this->assertSame('admin@sms.com', Mobile::normalize('admin@sms.com'));
        $this->assertSame('20260001', Mobile::normalize('20260001'));
        $this->assertSame('', Mobile::normalize(''));
    }

    public function test_validates_bangladeshi_mobile_numbers(): void
    {
        $this->assertTrue(Mobile::isValid('01711111111'));
        $this->assertTrue(Mobile::isValid('01399999999'));
        $this->assertFalse(Mobile::isValid('01211111111'));
        $this->assertFalse(Mobile::isValid('0171111111'));
        $this->assertFalse(Mobile::isValid('017111111111'));
        $this->assertFalse(Mobile::isValid('+8801711111111'));
    }
}
