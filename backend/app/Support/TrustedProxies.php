<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Applies the TRUSTED_PROXIES setting (config 'app.trusted_proxies'). Only the forwarded
 * for/proto/port headers are trusted, never X-Forwarded-Host.
 *
 * '*' is only safe when the app is reachable exclusively through a proxy that overwrites or
 * appends X-Forwarded-For; otherwise anyone can spoof their IP and bypass the login and
 * result-lookup limiters. Prefer listing the proxy's own IPs/CIDRs.
 */
class TrustedProxies
{
    public static function apply(mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $value = trim($value);

        TrustProxies::at($value === '*' ? '*' : array_map('trim', explode(',', $value)));
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
    }
}
