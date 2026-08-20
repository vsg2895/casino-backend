<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

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

            // Guarded by `! verified` so the timestamp records the FIRST click.
            // Re-clicking the link must not move it forward, or the
            // post-verification promotion delay would restart on every click.
            if ($newsletter !== null && ! $newsletter->verified) {
                $newsletter->forceFill([
                    'verified'    => true,
                    'verified_at' => Carbon::now(),
                ])->save();
            }
        }

        return response()->json(['ok' => true]);
    }
}
