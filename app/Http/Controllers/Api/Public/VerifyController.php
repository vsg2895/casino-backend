<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use Illuminate\Http\JsonResponse;

/**
 * Double opt-in email verification landing target.
 *
 * The subscriber's opaque subscription token (the same one used for one-click
 * unsubscribe) IS the credential, so no site key and no personal data travel in
 * the URL. Keyless like {@see UnsubscribeController}: any site's public front-end
 * can call it, and the global 64-char token resolves the subscriber.
 *
 * Idempotent and always returns ok — it never reveals whether a token exists,
 * and re-clicking a verified link is harmless.
 */
class VerifyController extends Controller
{
    public function verify(string $token): JsonResponse
    {
        if (strlen($token) === 64) {
            $newsletter = Newsletter::where('unsubscribe_token', $token)->first();

            if ($newsletter !== null && ! $newsletter->verified) {
                $newsletter->forceFill(['verified' => true])->save();
            }
        }

        return response()->json(['ok' => true]);
    }
}
