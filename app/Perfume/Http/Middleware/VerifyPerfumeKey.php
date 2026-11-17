<?php

declare(strict_types=1);

namespace App\Perfume\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/perfume/public/*: the storefront sends `X-Perfume-Key`, which
 * must equal config('perfume.api_key').
 *
 * Constant-time comparison, no slug, no table: there is exactly one
 * storefront. An EMPTY configured key refuses everything with 503 rather
 * than matching an empty header — a missing env value must never open the
 * API.
 */
class VerifyPerfumeKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('perfume.api_key', '');

        if ($expected === '') {
            abort(503, 'Perfume API key is not configured');
        }

        $provided = (string) $request->header('X-Perfume-Key', '');

        if ($provided === '') {
            abort(401, 'Missing perfume API key');
        }

        if (! hash_equals($expected, $provided)) {
            abort(403, 'Invalid perfume API key');
        }

        return $next($request);
    }
}
