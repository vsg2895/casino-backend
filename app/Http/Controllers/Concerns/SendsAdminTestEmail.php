<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Mail\Contracts\SenderOverridable;
use App\Models\EmailSchedule;
use App\Support\Mail\MailCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The single send path shared by the admin "Send test" buttons of the three
 * per-site TEMPLATE sections: Subscription Emails, Verify Email and Promotion
 * Emails. Keeping it in one place guarantees the three behave identically — the
 * only thing that differs is which template each controller builds.
 *
 * TRANSPORT: the .env SMTP mailer, config('mail.admin_test_mailer') — a literal
 * 'smtp' rather than config('mail.admin_mailer'). These buttons exist to prove
 * the operator's own SMTP server accepts and delivers a template; following a
 * variable would let a changed MAIL_ADMIN_MAILER quietly turn them into a test
 * of something else, and a broken SMTP setup would keep reporting success.
 *
 * FROM: config('mail.from.address') — the authenticated .env mailbox
 * (MAIL_FROM_ADDRESS), so a self-hosted mail server accepts the message. The
 * mailable's own from_name stays the display name.
 *
 * NOT ROUTED THROUGH HERE, on purpose:
 *  - Promotion After Verification's test, which must mirror its real send
 *    (SendGrid via .env, From taken from that section's own template);
 *  - the SendGrid- and Mailgun-key "Test" buttons, which exist to verify one
 *    specific STORED credential and must use that key.
 */
trait SendsAdminTestEmail
{
    protected function sendAdminTestEmail(Mailable&SenderOverridable $mailable, string $to): JsonResponse
    {
        $mailer = (string) config('mail.admin_test_mailer', 'smtp');
        $from = config('mail.from.address') ?: null;
        // Recorded so "which credentials did that test use?" is answerable from
        // the log. SMTP authenticates with MAIL_USERNAME/MAIL_PASSWORD, so there
        // is no API key here to fingerprint.
        $credential = MailCredential::describe(EmailSchedule::PROVIDER_SMTP, null);

        $mailable->usingFromAddress($from);

        try {
            $sent = Mail::mailer($mailer)->to($to)->send($mailable);
        } catch (Throwable $e) {
            // Logged as well as returned: without this, a failed test left no
            // trace behind, so "the test never arrived" reports had no history
            // to investigate.
            Log::warning('Admin test email failed', [
                'to'       => $to,
                'mailable' => $mailable::class,
                'mailer'   => $mailer,
                'from'     => $from,
                'error'    => $e->getMessage(),
                ...$credential,
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Could not send test email: ' . $e->getMessage(),
            ], 502);
        }

        // The transport's own id for this message, when it exposes one. Without
        // it, "it never arrived" cannot be told apart from "it was never
        // accepted".
        $messageId = $sent?->getSymfonySentMessage()?->getMessageId();

        Log::info('Admin test email sent', [
            'to'         => $to,
            'mailable'   => $mailable::class,
            'mailer'     => $mailer,
            'from'       => $from,
            'message_id' => $messageId,
            ...$credential,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => "Test email sent to {$to} from {$from} via {$credential['source']}"
                . ($messageId ? " — message id {$messageId}" : '') . '.',
        ]);
    }
}
