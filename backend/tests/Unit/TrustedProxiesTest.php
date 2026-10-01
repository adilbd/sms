<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    /** The client IP the app sees for a request from $remote that claims to be 203.0.113.9. */
    private function clientIp(string $remote): string
    {
        $request = Request::create('http://localhost/up', 'GET', [], [], [], ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
        $seen = null;

        (new TrustProxies)->handle($request, function (Request $r) use (&$seen) {
            $seen = $r;

            return response('ok');
        });

        return $seen->ip();
    }

    public function test_empty_entries_from_a_trailing_or_doubled_comma_are_ignored(): void
    {
        TrustedProxies::apply('10.0.0.1,,');

        $this->assertSame('203.0.113.9', $this->clientIp('10.0.0.1'));
        $this->assertSame('10.0.0.2', $this->clientIp('10.0.0.2'));

        TrustedProxies::apply(', 10.0.0.1 ,, 10.0.0.3');

        $this->assertSame('203.0.113.9', $this->clientIp('10.0.0.3'));
    }

    public function test_an_empty_or_null_value_clears_earlier_trust(): void
    {
        foreach ([null, '', '  ', ',', false] as $empty) {
            TrustedProxies::apply('10.0.0.1');
            $this->assertSame('203.0.113.9', $this->clientIp('10.0.0.1'));

            TrustedProxies::apply($empty);

            $this->assertSame('10.0.0.1', $this->clientIp('10.0.0.1'), 'trust survived '.var_export($empty, true));
        }
    }
}
