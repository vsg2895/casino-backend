<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Mail\Contracts\SenderOverridable;
use Illuminate\Http\JsonResponse;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The single send path shared by every admin "Send test" button (subscription,
 * verify, promotion). Keeping it in one place guarantees the three types behave
 * identically — the only thing that differs is which template each controller
 * builds into the mailable.
 *
 * Test mail always goes over the .env SMTP mailer FROM the authenticated mailbox
 * (config('mail.from.address')) so a self-hosted mail server accepts it; the
 * mailable's own from_name stays the display name.
 *
 * The transport is PINNED to config('mail.admin_test_mailer') — a literal
 * 'smtp' — rather than config('mail.admin_mailer'). These buttons exist to prove
 * the operator's own SMTP server accepts and delivers a template; following a
 * variable would let a changed MAIL_ADMIN_MAILER quietly turn them into a test
 * of SendGrid instead, and a broken SMTP setup would keep reporting success.
 * Provider-specific verification has its own buttons on the SendGrid and Mailgun
 * key rows.
 */
trait SendsAdminTestEmail
{
    protected function sendAdminTestEmail(Mailable&SenderOverridable $mailable, string $to): JsonResponse
    {
        $mailable->usingFromAddress(config('mail.from.address') ?: null);

        try {
            Mail::mailer(config('mail.admin_test_mailer', 'smtp'))->to($to)->send($mailable);
        } catch (Throwable $e) {
            // Logged as well as returned: without this, a failed test left no
            // trace behind, so "the test never arrived" reports had no history
            // to investigate.
            Log::warning('Admin test email failed', [
                'to'       => $to,
                'mailable' => $mailable::class,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Could not send test email: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json(['ok' => true, 'message' => "Test email sent to {$to}."]);
    }
}
