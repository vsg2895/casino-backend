<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Mail\Contracts\SenderOverridable;
use App\Models\EmailSchedule;
use App\Models\Site;
use App\Support\Mail\MailCredential;
use App\Support\Mail\SiteSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The single send path shared by every admin "Send test" button that tests a
 * TEMPLATE (subscription, verify, per-site promotion). Keeping it in one place
 * guarantees the three behave identically — the only thing that differs is which
 * template each controller builds into the mailable.
 *
 * TRANSPORT: the .env SendGrid mailer, config('mail.public_mailer') — the exact
 * one a visitor's subscribe/verify email goes out through.
 *
 * This is a deliberate change from the previous behaviour, which pinned these
 * buttons to SMTP so they would prove the operator's own mail server worked.
 * That made the test answer a question nobody was asking: these templates are
 * only ever delivered to real people over SendGrid, so a test that passed over
 * SMTP — from a different domain, with different authentication — proved
 * nothing about whether the real thing arrives. Now a green test means the real
 * delivery path works.
 *
 * FROM: the SendGrid-authenticated public sender, resolved by {@see SiteSender}
 * — the same helper the live verify email uses. SendGrid only accepts mail from
 * a sender it has authenticated, so the .env SMTP mailbox (a different domain)
 * would send without error and never arrive.
 *
 * NOTE: the SendGrid- and Mailgun-key "Test" buttons do NOT come through here,
 * on purpose. Those exist to verify one specific STORED credential, so they must
 * use that key rather than the .env one.
 */
trait SendsAdminTestEmail
{
    protected function sendAdminTestEmail(
        Mailable&SenderOverridable $mailable,
        string $to,
        ?Site $site = null,
    ): JsonResponse {
        $mailer = (string) config('mail.public_mailer', 'sendgrid');
        $from = $this->adminTestFromAddress($site);
        // Reported so the admin can confirm WHICH credential a test used,
        // without shell access. Fingerprint only — never key material.
        $credential = MailCredential::describe(EmailSchedule::PROVIDER_SENDGRID_ENV, null);

        $mailable->usingFromAddress($from ?: null);

        try {
            Mail::mailer($mailer)->to($to)->send($mailable);
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
                'message' => 'Could not send test email via ' . $credential['source'] . ': ' . $e->getMessage(),
            ], 502);
        }

        Log::info('Admin test email sent', [
            'to'       => $to,
            'mailable' => $mailable::class,
            'mailer'   => $mailer,
            'from'     => $from,
            ...$credential,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => "Test email sent to {$to} from {$from} via {$credential['source']}"
                . " (key {$credential['key_prefix']} fingerprint {$credential['key_fingerprint']}).",
        ]);
    }

    /**
     * The SendGrid-authenticated sender for a test.
     *
     * With a site, this is byte-for-byte what that site's live verify email
     * uses. Without one, it falls back to the configured public sender, and only
     * then to the .env From — which keeps the method total even on an install
     * that has not set MAIL_PUBLIC_FROM_ADDRESS.
     */
    private function adminTestFromAddress(?Site $site): ?string
    {
        if ($site !== null) {
            return SiteSender::verificationAddress($site) ?: null;
        }

        $public = trim((string) config('mail.public_from_address', ''));

        return $public !== '' ? $public : (config('mail.from.address') ?: null);
    }
}
