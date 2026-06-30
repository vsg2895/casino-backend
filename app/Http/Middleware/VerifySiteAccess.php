<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class VerifySiteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('site');
        $providedKey = $request->header('X-Site-Key');

        if (! $slug || ! $providedKey) {
            abort(401, 'Missing site credentials');
        }

        $site = Site::where('slug', $slug)->where('active', true)->first();

        if (! $site) {
            abort(404, 'Site not found');
        }

        if (! Hash::check($providedKey, $site->api_key)) {
            abort(403, 'Invalid site key');
        }

        $request->merge(['_site' => $site]);
        app()->instance('current_site', $site);

        return $next($request);
    }
}
