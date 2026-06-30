<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * API-only application — never redirect to a login page.
     * Returning null causes the framework to throw AuthenticationException
     * which is then rendered as a JSON 401 by the exception handler.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
