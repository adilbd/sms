<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /portal response is private: never stored by a browser, proxy or CDN, and never
 * indexed. Applied to the login page too, and to the private admission pages (the
 * confirmation and the status lookup), which share the same need.
 */
class PortalHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
