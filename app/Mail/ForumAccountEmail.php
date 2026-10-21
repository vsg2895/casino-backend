<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasSenderOverride;
use App\Mail\Contracts\SenderOverridable;
use App\Services\Mail\Transport\SendgridClickTrackingClient;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The two transactional emails a forum account needs: confirm your address, and
 * reset your password.
 *
 * ── Why this is NOT one of the per-site editable templates ──────────────────
 *
 * The messaging subsystem's templates (subscription, verify, promotion) are
 * MARKETING copy an operator edits freely. These two are security emails whose
 * only job is to carry a link the recipient must be able to trust. An editable
 * template invites an operator to reword the one sentence that says "we did not
 * send this, ignore it" — so the copy stays in code.
 *
 * It still uses the site's own sender identity, so a member who registered on
 * winpalack gets mail from winpalack and SPF/DKIM line up per domain. That
 * From-domain rule is the one that has bitten this project before: mail that
 * sends without error and never arrives is almost always a From domain that does
 * not match the authenticated sending domain.
 */
class ForumAccountEmail extends Mailable implements SenderOverridable
{
    use HasSenderOverride;
    use Queueable;
    use SerializesModels;

    public const string TYPE_VERIFY = 'verify';

    public const string TYPE_RESET = 'reset';

    public function __construct(
        public readonly Site $site,
        public readonly string $type,
        public readonly string $displayName,
        public readonly string $actionUrl,
        /** Minutes the link stays valid, for the reset variant. */
        public readonly ?int $expiresInMinutes = null,
    ) {}

    public function envelope(): Envelope
    {
        $brand = $this->site->name;

        return new Envelope(
            subject: $this->type === self::TYPE_VERIFY
                ? "Confirm your {$brand} forum account"
                : "Reset your {$brand} forum password",
            // Per-site From, applied by the send path. The display name stays
            // the site's — see SiteSender and the job's docblock for why the
            // domain has to match the authenticated sending domain.
            from: $this->fromAddressOverride !== null
                ? new \Illuminate\Mail\Mailables\Address($this->fromAddressOverride, $brand)
                : null,
        );
    }

    /**
     * CLICK TRACKING OFF, for the same reason the newsletter's verification
     * email turns it off — and this message needed it just as badly.
     *
     * SendGrid rewrites every link in a tracked message to a redirector. With
     * a branded link domain that is `urlNNNN.<domain>`; without one it is
     * `sendgrid.net`. Either way the recipient is asked to confirm their
     * identity by clicking a host they have never seen, and if the branded
     * subdomain's certificate is not provisioned the browser refuses it
     * outright — which is what "this address is not trusted" was.
     *
     * The symptom had a second tell nobody connected at the time: Search
     * Console reported 404s on `url7462.winpalack.com`, a tracking host for
     * this very domain.
     *
     * Both links in this email are the same signed URL, shown as a button and
     * as pasted text, so untracked they also agree with each other — a tracked
     * button that disagrees with the fallback text underneath it is precisely
     * the shape a phishing filter looks for.
     *
     * The marker never leaves the app: {@see SendgridClickTrackingClient}
     * strips it and swaps in `tracking_settings.click_tracking`. Setting it on
     * the message rather than the payload keeps this independent of the
     * transport — over SMTP it is an inert X- header.
     *
     * Applied to BOTH types. A password-reset link is, if anything, more
     * sensitive than a confirmation one.
     */
    public function headers(): Headers
    {
        return new Headers(text: [SendgridClickTrackingClient::DISABLE_HEADER => 'disabled']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.forum-account',
            with: [
                'brand'      => $this->site->name,
                'name'       => $this->displayName,
                'type'       => $this->type,
                'actionUrl'  => $this->actionUrl,
                'expiresIn'  => $this->expiresInMinutes,
            ],
        );
    }
}
