<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Mail\Contracts\SenderOverridable;
use Illuminate\Http\JsonResponse;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The single send path shared by every admin "Send test" button (subscription,
 * verify, promotion). Keeping it in one place guarantees the three types behave
 * identically — the only thing that differs is which template each controller
 * builds into the mailable.
 *
 * Admin mail always goes over the .env SMTP mailer (config('mail.admin_mailer'))
 * FROM the authenticated mailbox (config('mail.from.address')) so a self-hosted
 * mail server accepts it; the mailable's own from_name stays the display name.
 */
trait SendsAdminTestEmail
{
    protected function sendAdminTestEmail(Mailable&SenderOverridable $mailable, string $to): JsonResponse
    {
        $mailable->usingFromAddress(config('mail.from.address') ?: null);

        try {
            Mail::mailer(config('mail.admin_mailer'))->to($to)->send($mailable);
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Could not send test email: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json(['ok' => true, 'message' => "Test email sent to {$to}."]);
    }
}
