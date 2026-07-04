<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admin-defined scheduled promotion campaign. See the create_email_schedules
 * migration. {@see dateRange()} resolves the subscriber window (on
 * newsletters.created_at) and {@see isDue()} decides whether it should run now.
 */
class EmailSchedule extends Model
{
    // Subscriber window (relative to "now" when the campaign runs).
    public const string FILTER_TODAY = 'today';
    public const string FILTER_YESTERDAY = 'yesterday';
    public const string FILTER_LAST_WEEK = 'last_week';
    public const string FILTER_LAST_MONTH = 'last_month';
    public const string FILTER_LAST_QUARTER = 'last_quarter';
    public const string FILTER_LAST_YEAR = 'last_year';
    public const string FILTER_SPECIFIC = 'specific';

    /** @var list<string> */
    public const array DATE_FILTERS = [
        self::FILTER_TODAY, self::FILTER_YESTERDAY, self::FILTER_LAST_WEEK,
        self::FILTER_LAST_MONTH, self::FILTER_LAST_QUARTER, self::FILTER_LAST_YEAR,
        self::FILTER_SPECIFIC,
    ];

    // Cadence.
    public const string FREQ_DAILY = 'daily';
    public const string FREQ_WEEKLY = 'weekly';
    public const string FREQ_MONTHLY = 'monthly';

    /** @var list<string> */
    public const array FREQUENCIES = [self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_MONTHLY];

    protected $fillable = [
        'site_id',
        'name',
        'date_filter',
        'specific_date',
        'limit',
        'frequency',
        'time',
        'day_of_week',
        'day_of_month',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'specific_date' => 'date',
            'limit'         => 'integer',
            'day_of_week'   => 'integer',
            'day_of_month'  => 'integer',
            'active'        => 'boolean',
            'last_run_at'   => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Whether this schedule targets the newest N subscribers (by created_at)
     * rather than a sign-up date window. True when no date filter is set.
     */
    public function usesLimit(): bool
    {
        return $this->date_filter === null;
    }

    /**
     * Inclusive [start, end] window on newsletters.created_at for this campaign,
     * resolved relative to $now. Always spans whole days (start-of-day →
     * end-of-day) so partial-day times never exclude a subscriber.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function dateRange(CarbonInterface $now): array
    {
        return match ($this->date_filter) {
            self::FILTER_TODAY        => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            self::FILTER_YESTERDAY    => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            self::FILTER_LAST_WEEK    => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            self::FILTER_LAST_MONTH   => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            self::FILTER_LAST_QUARTER => [$now->copy()->subQuarterNoOverflow()->startOfQuarter(), $now->copy()->subQuarterNoOverflow()->endOfQuarter()],
            self::FILTER_LAST_YEAR    => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            self::FILTER_SPECIFIC     => [
                Carbon::parse((string) $this->specific_date)->startOfDay(),
                Carbon::parse((string) $this->specific_date)->endOfDay(),
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
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
