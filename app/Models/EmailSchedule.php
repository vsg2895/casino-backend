<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Mail\CustomEmailContent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin-defined scheduled promotion campaign: a site's promotion email, sent
 * to the newest `limit` addresses of one list, skipping anyone who received that
 * template from that site within `cooldown_days`.
 *
 * {@see isDue()} decides whether it should run now; who it reaches is
 * {@see \App\Services\ScheduleRecipientService}'s alone.
 *
 * `date_filter` / `specific_date` are retired columns — the sign-up-date
 * audience was replaced by count + cooldown — and nothing reads them.
 */
class EmailSchedule extends Model
{
    /*
     * Which list a schedule mails.
     *
     * Newsletters are the site's own subscribers. The other three are global
     * lists that carry no site: the site only chooses the template, and the
     * cooldown is still per site.
     */
    public const string AUDIENCE_NEWSLETTERS = 'newsletters';
    public const string AUDIENCE_MAILGUN_RECEIVERS = 'mailgun_receivers';
    public const string AUDIENCE_SENDGRID_RECEIVERS = 'sendgrid_receivers';
    public const string AUDIENCE_WARMUP = 'warmup';

    /** @var list<string> */
    public const array AUDIENCES = [
        self::AUDIENCE_NEWSLETTERS,
        self::AUDIENCE_MAILGUN_RECEIVERS,
        self::AUDIENCE_SENDGRID_RECEIVERS,
        self::AUDIENCE_WARMUP,
    ];

    /** Human labels, for history rows and messages. */
    public const array AUDIENCE_LABELS = [
        self::AUDIENCE_NEWSLETTERS        => 'Newsletters',
        self::AUDIENCE_MAILGUN_RECEIVERS  => 'Mailgun Receivers',
        self::AUDIENCE_SENDGRID_RECEIVERS => 'Sendgrid Receivers',
        self::AUDIENCE_WARMUP             => 'Warmup',
    ];

    /*
     * WHICH email a schedule sends.
     *
     * The first three are the site's own stored templates — the same keys
     * {@see \App\Services\Mail\EmailTemplateCatalog} uses, so one template
     * means one string in the schedule, the send history, the warmup history
     * and the admin dropdowns alike.
     *
     * TEMPLATE_CUSTOM has no stored row: its From pair, subject and HTML are
     * typed into the schedule and live in the `custom_*` columns.
     */
    public const string TEMPLATE_PROMOTION = 'promotion';
    public const string TEMPLATE_SUBSCRIBE = 'subscribe';
    public const string TEMPLATE_VERIFY = 'verify';
    public const string TEMPLATE_CUSTOM = 'custom';

    /** @var list<string> */
    public const array TEMPLATES = [
        self::TEMPLATE_PROMOTION,
        self::TEMPLATE_SUBSCRIBE,
        self::TEMPLATE_VERIFY,
        self::TEMPLATE_CUSTOM,
    ];

    /** Human labels, for the admin and for refusal messages. */
    public const array TEMPLATE_LABELS = [
        self::TEMPLATE_PROMOTION => 'Promotion email',
        self::TEMPLATE_SUBSCRIBE => 'Subscription email',
        self::TEMPLATE_VERIFY    => 'Verify email',
        self::TEMPLATE_CUSTOM    => 'Custom HTML',
    ];

    /**
     * Templates only the `newsletters` audience may be sent, enforced in
     * {@see \App\Http\Requests\Admin\StoreEmailScheduleRequest}.
     *
     * Just the verify email, and for one reason: it asks the recipient to
     * CONFIRM an address they submitted themselves, and the button is resolved
     * from their subscriber row. Every address on a global list was entered by
     * an operator who already has it — there is no opt-in claim to confirm, so
     * the button would have nothing to do.
     *
     * TEMPLATE_SUBSCRIBE is deliberately NOT in here any more. It was until
     * 2026-12, because it too is built from the recipient's own token and only
     * a subscriber had one. Every global list now carries its own
     * ({@see \App\Models\Contracts\TokenAddressable}), so a welcome message to
     * a Custom Receivers or warmup address carries a working opt-out for the
     * list that mailed them, and the restriction had no reason left to exist.
     *
     * What gates a send either way is whether the template is switched ON for
     * the site — see {@see templateActiveFor()} and {@see templateBlocker()}.
     *
     * @var list<string>
     */
    public const array SUBSCRIBER_ONLY_TEMPLATES = [self::TEMPLATE_VERIFY];

    /** Bounds on the count per run and on the cooldown, shared by request and UI. */
    public const int MAX_LIMIT = 100000;

    public const int MIN_COOLDOWN_DAYS = 1;

    public const int MAX_COOLDOWN_DAYS = 365;

    // Cadence.
    public const string FREQ_DAILY = 'daily';
    public const string FREQ_WEEKLY = 'weekly';
    public const string FREQ_MONTHLY = 'monthly';

    /** @var list<string> */
    public const array FREQUENCIES = [self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_MONTHLY];

    // Delivery transport.
    public const string PROVIDER_SMTP = 'smtp';         // .env SMTP (default)
    public const string PROVIDER_SENDGRID = 'sendgrid'; // stored SendGrid key
    public const string PROVIDER_MAILGUN = 'mailgun';   // stored Mailgun credential
    public const string PROVIDER_SENDGRID_ENV = 'sendgrid_env'; // .env SENDGRID_API_KEY, no stored key

    /**
     * Transports a SCHEDULED CAMPAIGN may be saved with.
     *
     * PROVIDER_SENDGRID_ENV is deliberately absent: it exists for the
     * post-verification promotion (see {@see VerificationPromotionEmail::PROVIDERS}),
     * and adding it here would silently offer every campaign the environment key
     * as well — a change to campaign behaviour that nobody asked for.
     *
     * @var list<string>
     */
    public const array PROVIDERS = [self::PROVIDER_SMTP, self::PROVIDER_SENDGRID, self::PROVIDER_MAILGUN];

    /**
     * Which credential column each provider reads. SMTP is absent on purpose —
     * it is configured entirely from the environment and has no stored row.
     *
     * @var array<string, string>
     */
    public const array PROVIDER_CREDENTIAL_COLUMNS = [
        self::PROVIDER_SENDGRID => 'sendgrid_key_id',
        self::PROVIDER_MAILGUN  => 'mailgun_key_id',
    ];

    protected $fillable = [
        'site_id',
        'name',
        'template',
        // Custom HTML only; null for every stored-template schedule.
        'custom_from_name',
        'custom_from_address',
        'custom_subject',
        'custom_html',
        'audience',
        'limit',
        'cooldown_days',
        'include_unsubscribed',
        'frequency',
        'time',
        'day_of_week',
        'day_of_month',
        'provider',
        'sendgrid_key_id',
        'mailgun_key_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'limit'                => 'integer',
            'cooldown_days'        => 'integer',
            'include_unsubscribed' => 'boolean',
            'day_of_week'     => 'integer',
            'day_of_month'    => 'integer',
            'sendgrid_key_id' => 'integer',
            'mailgun_key_id'  => 'integer',
            'active'          => 'boolean',
            'last_run_at'     => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The stored SendGrid key this schedule sends through (provider=sendgrid). */
    public function sendgridKey(): BelongsTo
    {
        return $this->belongsTo(SendgridKey::class);
    }

    /** The stored Mailgun credential this schedule sends through (provider=mailgun). */
    public function mailgunKey(): BelongsTo
    {
        return $this->belongsTo(MailgunKey::class);
    }

    /** Whether this schedule delivers via a stored SendGrid key rather than SMTP. */
    public function usesSendgrid(): bool
    {
        return $this->provider === self::PROVIDER_SENDGRID;
    }

    /** Whether this schedule delivers via a stored Mailgun credential. */
    public function usesMailgun(): bool
    {
        return $this->provider === self::PROVIDER_MAILGUN;
    }

    /**
     * The credential row id for whichever provider is selected, or null for
     * SMTP (and for any provider with no stored credential).
     *
     * This is what travels into the send jobs, so the batch job never has to
     * know which providers exist — adding one is a new entry in
     * {@see PROVIDER_CREDENTIAL_COLUMNS}, not a new job parameter.
     */
    public function providerCredentialId(): ?int
    {
        $column = self::PROVIDER_CREDENTIAL_COLUMNS[$this->provider] ?? null;

        return $column === null ? null : ($this->{$column} === null ? null : (int) $this->{$column});
    }

    /** The audience, defaulting to newsletters for a row that predates the column. */
    public function audienceKey(): string
    {
        return in_array($this->audience, self::AUDIENCES, true) ? (string) $this->audience : self::AUDIENCE_NEWSLETTERS;
    }

    /**
     * Which email this schedule sends, defaulting to the promotion template.
     *
     * The default is what makes every schedule written before this column
     * existed — and every client that does not send the field — behave exactly
     * as it always has.
     */
    public function templateKey(): string
    {
        return in_array($this->template, self::TEMPLATES, true) ? (string) $this->template : self::TEMPLATE_PROMOTION;
    }

    /** This schedule's template, in words. */
    public function templateLabel(): string
    {
        return self::TEMPLATE_LABELS[$this->templateKey()] ?? $this->templateKey();
    }

    /** Whether this schedule carries its own HTML rather than a stored template. */
    public function usesCustomTemplate(): bool
    {
        return $this->templateKey() === self::TEMPLATE_CUSTOM;
    }

    /**
     * The typed subject + HTML of a Custom HTML schedule, or null for any other
     * template — and for a custom one that is missing either half, which is a
     * schedule that must send nothing rather than an empty email.
     */
    public function customContent(): ?CustomEmailContent
    {
        if (! $this->usesCustomTemplate()) {
            return null;
        }

        return CustomEmailContent::make($this->custom_subject, $this->custom_html);
    }

    /**
     * The display name on a Custom HTML send, or null to leave the transport's.
     */
    public function customFromName(): ?string
    {
        $name = is_string($this->custom_from_name) ? trim($this->custom_from_name) : '';

        return $name === '' ? null : $name;
    }

    /**
     * The From ADDRESS a Custom HTML schedule sends as, or null to keep the
     * transport's authenticated mailbox.
     *
     * Deliberately the admin's to choose, because a custom campaign is often
     * the one that needs its own sender. It is also the one setting here that
     * can silently destroy deliverability: a From domain the sending account is
     * not authorised for fails SPF/DKIM alignment, and the message is filtered
     * rather than bounced — "sends, no error, never arrives". The admin form
     * says so beside the field; nothing here can verify it.
     */
    public function customFromAddress(): ?string
    {
        $address = is_string($this->custom_from_address) ? trim($this->custom_from_address) : '';

        return $address === '' ? null : $address;
    }

    /**
     * The address this schedule's messages are sent from: the custom one when
     * it has it, otherwise the transport's own (the authenticated mailbox).
     */
    public function fromAddressFor(?string $transportAddress): ?string
    {
        return ($this->usesCustomTemplate() ? $this->customFromAddress() : null) ?? $transportAddress;
    }

    /**
     * Whether the template this schedule sends is switched on for its site.
     *
     * Custom HTML has no stored row to switch off — the schedule's own `active`
     * flag is its only gate.
     */
    public function templateActiveFor(Site $site): bool
    {
        return match ($this->templateKey()) {
            self::TEMPLATE_SUBSCRIBE => (bool) $site->emailTemplateOrDefault()->active,
            self::TEMPLATE_VERIFY    => (bool) $site->verifyEmailOrDefault()->active,
            self::TEMPLATE_CUSTOM    => true,
            default                  => (bool) $site->promotionEmailOrDefault()->active,
        };
    }

    /**
     * Why this schedule's template would send nothing for its site, in words
     * an admin can act on — or null when it is ready to send.
     */
    public function templateBlocker(Site $site): ?string
    {
        if ($this->usesCustomTemplate() && $this->customContent() === null) {
            return 'This schedule sends custom HTML but has no subject or body saved. Edit it and fill both in.';
        }

        if (! $this->templateActiveFor($site)) {
            return "The {$this->templateLabel()} for {$site->name} is switched off, so nothing would be sent.";
        }

        return null;
    }

    /**
     * The receiver list model for a list audience, or null for newsletters.
     *
     * @return class-string<Model>|null
     */
    public function audienceModel(): ?string
    {
        return self::modelForAudience($this->audienceKey());
    }

    /**
     * The list model behind an audience key, or null for newsletters (and for
     * anything unknown, which is never a list).
     *
     * @return class-string<Model>|null
     */
    public static function modelForAudience(string $audience): ?string
    {
        return match ($audience) {
            self::AUDIENCE_MAILGUN_RECEIVERS  => MailgunReceiver::class,
            self::AUDIENCE_SENDGRID_RECEIVERS => SendgridReceiver::class,
            self::AUDIENCE_WARMUP             => WarmupEmail::class,
            default                           => null,
        };
    }

    /** How many addresses one run takes; 0 when no count is set. */
    public function runLimit(): int
    {
        return max(0, (int) $this->limit);
    }

    /** Days an address rests after receiving this site's promotion, clamped to the allowed range. */
    public function cooldownDays(): int
    {
        return max(self::MIN_COOLDOWN_DAYS, min((int) ($this->cooldown_days ?? 1), self::MAX_COOLDOWN_DAYS));
    }

    /**
     * Whether this schedule skips the site's opt-out list.
     *
     * FALSE for every schedule unless an admin deliberately turned it on, and
     * false for every row written before the column existed. When it is false —
     * which is the whole network, by default — an address that unsubscribed
     * from this site through ANY template receives nothing from this site
     * again, on any audience: its own subscribers, a Custom Receivers list, or
     * warmup. See {@see \App\Models\Unsubscribe::hasAny()}.
     */
    public function includesUnsubscribed(): bool
    {
        return (bool) $this->include_unsubscribed;
    }

    /**
     * Whether this schedule must honour the site's opt-out list — the inverse,
     * spelled out because that is how every caller reads it.
     */
    public function honoursOptOut(): bool
    {
        return ! $this->includesUnsubscribed();
    }

    /**
     * Why this schedule would send nothing, or null when it can run.
     *
     * The one configuration hole the model itself can see: a schedule saved
     * before counts existed (a sign-up-date schedule) has no `limit`. It sends
     * nobody until an admin gives it one — mailing "the whole list" in its
     * place would turn a daily trickle into a blast.
     */
    public function runBlocker(): ?string
    {
        if ($this->runLimit() < 1) {
            return 'This schedule has no count of emails to send. Edit it and set one.';
        }

        return null;
    }

    /**
     * "SendGrid · Main key" — what the history records as the email config used.
     *
     * Resolved by id at send time and stored as text, so a key renamed or
     * deleted later leaves the history readable.
     */
    public static function credentialLabel(string $provider, ?int $credentialId): string
    {
        return match ($provider) {
            self::PROVIDER_SENDGRID => 'SendGrid · ' . (SendgridKey::query()->whereKey($credentialId)->value('name') ?? 'key #' . $credentialId),
            self::PROVIDER_MAILGUN  => 'Mailgun · ' . (MailgunKey::query()->whereKey($credentialId)->value('name') ?? 'key #' . $credentialId),
            default                 => 'SMTP (.env)',
        };
    }

    /** Whether this active schedule should fire at the given minute. */
    public function isDue(CarbonInterface $now): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($now->format('H:i') !== $this->normalizedTime()) {
            return false;
        }

        return match ($this->frequency) {
            self::FREQ_DAILY   => true,
            self::FREQ_WEEKLY  => $now->dayOfWeek === $this->day_of_week,
            // Clamp so e.g. "31st" still fires on the last day of a short month.
            self::FREQ_MONTHLY => $now->day === min((int) $this->day_of_month, $now->daysInMonth),
            default            => false,
        };
    }

    /** True if this schedule already fired during $now's minute (idempotency guard). */
    public function ranAt(CarbonInterface $now): bool
    {
        return $this->last_run_at !== null
            && $this->last_run_at->format('Y-m-d H:i') === $now->format('Y-m-d H:i');
    }

    /** Stored time normalised to HH:MM for comparison. */
    private function normalizedTime(): string
    {
        return substr((string) $this->time, 0, 5);
    }
}
