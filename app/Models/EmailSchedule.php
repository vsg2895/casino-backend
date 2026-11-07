<?php

declare(strict_types=1);

namespace App\Models;

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

    /** The template every schedule sends: the site's promotion email. */
    public const string TEMPLATE_PROMOTION = 'promotion';

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
        'audience',
        'limit',
        'cooldown_days',
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
            'limit'         => 'integer',
            'cooldown_days' => 'integer',
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
