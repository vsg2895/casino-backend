<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasSenderOverride;
use App\Mail\Contracts\SenderOverridable;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
