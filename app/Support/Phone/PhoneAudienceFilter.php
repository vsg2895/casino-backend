<?php

declare(strict_types=1);

namespace App\Support\Phone;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The admin's selected filters, as one value object.
 *
 * This exists so "the selected filters must be preserved throughout the sending
 * process" is structurally true rather than a thing every caller remembers to do.
 * The listing, the count, the preview, the fan-out and every send batch all
 * receive the same object, built once from the request — there is no second place
 * where a filter is interpreted, so the numbers an admin sees before pressing
 * send are the numbers that get messaged.
 *
 * Filters compose as: date constraint (one of the modes below) + optional search
 * + optional cap on how many of the newest numbers to take. Opt-outs are NOT
 * part of this object; they are excluded unconditionally by
 * {@see \App\Models\NewsletterBasedOnPhone::scopeSubscribed()}, because that is
 * compliance rather than a preference.
 *
 * Travels into queued jobs as a plain array ({@see toArray()} / {@see fromArray()})
 * rather than as a serialised object. Deliberate: a job payload written by one
 * deploy can be read by the next, and an array cannot acquire a constructor
 * signature that an in-flight payload does not satisfy.
 */
final readonly class PhoneAudienceFilter
{
    // Windows relative to "now", matching the promotion schedule's vocabulary so
    // the two features read the same way to an admin.
    public const string MODE_TODAY = 'today';
    public const string MODE_YESTERDAY = 'yesterday';
    public const string MODE_LAST_WEEK = 'last_week';
    public const string MODE_LAST_MONTH = 'last_month';
    public const string MODE_LAST_QUARTER = 'last_quarter';
    public const string MODE_LAST_YEAR = 'last_year';

    // Absolute, admin-supplied dates.
    public const string MODE_ON = 'on';         // added on exactly this date
    public const string MODE_BEFORE = 'before'; // added before this date
    public const string MODE_AFTER = 'after';   // added after this date
    public const string MODE_RANGE = 'range';   // added between two dates

    /** @var list<string> */
    public const array MODES = [
        self::MODE_TODAY, self::MODE_YESTERDAY, self::MODE_LAST_WEEK,
        self::MODE_LAST_MONTH, self::MODE_LAST_QUARTER, self::MODE_LAST_YEAR,
        self::MODE_ON, self::MODE_BEFORE, self::MODE_AFTER, self::MODE_RANGE,
    ];

    /** Modes that need `date_from` supplied. */
    private const array NEEDS_DATE_FROM = [
        self::MODE_ON, self::MODE_BEFORE, self::MODE_AFTER, self::MODE_RANGE,
    ];

    public ?string $mode;
    public ?string $dateFrom;
    public ?string $dateTo;
    public ?int $limit;
    public ?string $search;

    /**
     * Every value is normalised HERE, in the constructor, so the invariant holds
     * for every construction path rather than only for the named constructors
     * below.
     *
     * That placement is the whole point. With normalisation in fromRequest() only,
     * `new PhoneAudienceFilter(mode: 'on', dateFrom: '2026-13-45')` threw an
     * uncaught parse exception from resolvedRange(), and — far worse —
     * `dateFrom: 'next tuesday'` or `'0000-00-00'` was accepted by Carbon::parse()
     * and turned a typo into a real audience that would have been messaged.
     * Normalising on the way in means {@see from()} and {@see to()} can never
     * throw and can never resolve to a date nobody asked for.
     *
     * Nothing throws on bad input: an unusable mode or date becomes null, which
     * degrades to "no date constraint". For the send path that state is
     * unreachable — {@see \App\Http\Requests\Admin\SendBulkSmsRequest} rejects a
     * date mode with no date at the HTTP layer — so the degradation only ever
     * affects a listing, where showing everything beats a 500.
     */
    public function __construct(
        ?string $mode = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $limit = null,
        ?string $search = null,
    ) {
        $this->mode = $mode !== null && in_array($mode, self::MODES, true) ? $mode : null;
        $this->dateFrom = self::normaliseDate($dateFrom);
        $this->dateTo = self::normaliseDate($dateTo);
        $this->limit = $limit !== null && $limit > 0 ? $limit : null;

        $search = $search === null ? '' : trim($search);
        $this->search = $search === '' ? null : $search;
    }

    /** Build from an admin request. */
    public static function fromRequest(Request $request): self
    {
        $limit = $request->integer('limit');

        return new self(
            mode: self::stringOrNull($request->query('mode')),
            dateFrom: self::stringOrNull($request->query('date_from')),
            dateTo: self::stringOrNull($request->query('date_to')),
            limit: $limit > 0 ? $limit : null,
            search: self::stringOrNull($request->query('search')),
        );
    }

    /**
     * Rebuild from a queued job's payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $limit = isset($data['limit']) && is_numeric($data['limit']) ? (int) $data['limit'] : null;

        return new self(
            mode: self::stringOrNull($data['mode'] ?? null),
            dateFrom: self::stringOrNull($data['date_from'] ?? null),
            dateTo: self::stringOrNull($data['date_to'] ?? null),
            limit: $limit,
            search: self::stringOrNull($data['search'] ?? null),
        );
    }

    /**
     * A scalar as a string, or null for anything else.
     *
     * Query parameters are attacker-controlled and can arrive as arrays
     * (`?mode[]=x`), which would otherwise reach a string type hint and 500.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode'      => $this->mode,
            'date_from' => $this->dateFrom,
            'date_to'   => $this->dateTo,
            'limit'     => $this->limit,
            'search'    => $this->search,
        ];
    }

    /** Whether the audience is capped to the newest N numbers. */
    public function usesLimit(): bool
    {
        return $this->limit !== null && $this->limit > 0;
    }

    /**
     * The inclusive [start, end] window on created_at, or null when no date
     * constraint applies.
     *
     * Always spans whole days — start-of-day to end-of-day — so a number added at
     * 09:00 is never excluded by a filter expressed as a date. Same rule as
     * {@see \App\Models\EmailSchedule::dateRange()}.
     *
     * Open-ended modes ("before" / "after") return null on the side that is
     * unbounded, so callers must handle a null endpoint.
     *
     * @return array{0: CarbonInterface|null, 1: CarbonInterface|null}|null
     */
    public function resolvedRange(?CarbonInterface $now = null): ?array
    {
        if ($this->mode === null) {
            return null;
        }

        $now ??= Carbon::now();

        // An absolute mode with no date is not a filter — treat it as absent
        // rather than silently resolving to "today" and mailing the wrong people.
        if (in_array($this->mode, self::NEEDS_DATE_FROM, true) && $this->dateFrom === null) {
            return null;
        }

        return match ($this->mode) {
            self::MODE_TODAY        => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            self::MODE_YESTERDAY    => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            self::MODE_LAST_WEEK    => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            self::MODE_LAST_MONTH   => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            self::MODE_LAST_QUARTER => [$now->copy()->subQuarterNoOverflow()->startOfQuarter(), $now->copy()->subQuarterNoOverflow()->endOfQuarter()],
            self::MODE_LAST_YEAR    => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],

            self::MODE_ON     => [$this->from()->startOfDay(), $this->from()->endOfDay()],
            // "Before" and "after" exclude the named day itself, which is what an
            // admin means by it: "before 1 March" is not "including 1 March".
            //
            // Both endpoints are expressed as a whole-day boundary rather than by
            // stepping one second off the adjacent day. That is not cosmetic:
            // Carbon's endOfDay() carries .999999 microseconds, so the obvious
            // `endOfDay()->addSecond()` lands on 00:00:00.999999 — and because
            // MySQL DATETIME stores no microseconds, every row created in the
            // first second of that day would fail the `>=` and be silently
            // dropped from the audience.
            self::MODE_BEFORE => [null, $this->from()->subDay()->endOfDay()],
            self::MODE_AFTER  => [$this->from()->addDay()->startOfDay(), null],
            self::MODE_RANGE  => [
                $this->from()->startOfDay(),
                // A range with no end is open-ended rather than a one-day range.
                $this->dateTo === null ? null : $this->to()->endOfDay(),
            ],

            default => null,
        };
    }

    /** A short human description, for log lines and the admin confirmation. */
    public function describe(): string
    {
        $parts = [];

        $parts[] = match ($this->mode) {
            null                    => 'all numbers',
            self::MODE_TODAY        => 'added today',
            self::MODE_YESTERDAY    => 'added yesterday',
            self::MODE_LAST_WEEK    => 'added last week',
            self::MODE_LAST_MONTH   => 'added last month',
            self::MODE_LAST_QUARTER => 'added last quarter',
            self::MODE_LAST_YEAR    => 'added last year',
            self::MODE_ON           => "added on {$this->dateFrom}",
            self::MODE_BEFORE       => "added before {$this->dateFrom}",
            self::MODE_AFTER        => "added after {$this->dateFrom}",
            self::MODE_RANGE        => $this->dateTo === null
                ? "added from {$this->dateFrom}"
                : "added {$this->dateFrom} → {$this->dateTo}",
            default                 => 'all numbers',
        };

        if ($this->usesLimit()) {
            $parts[] = "newest {$this->limit}";
        }

        if ($this->search !== null) {
            $parts[] = "matching \"{$this->search}\"";
        }

        return implode(', ', $parts);
    }

    private function from(): CarbonInterface
    {
        return Carbon::parse((string) $this->dateFrom);
    }

    private function to(): CarbonInterface
    {
        return Carbon::parse((string) $this->dateTo);
    }

    /**
     * A Y-m-d date, or null when the value is not one.
     *
     * Strict: `Carbon::parse()` would happily read "next tuesday" or "0000-00-00"
     * and turn a typo into a real audience. Round-tripping through the exact
     * format is what rejects those.
     */
    private static function normaliseDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $raw = trim($value);

        // Accept a full ISO timestamp too — date pickers commonly send one — but
        // keep only the date part, since every window spans whole days.
        if (strlen($raw) > 10) {
            $raw = substr($raw, 0, 10);
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $raw);
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $raw ? $raw : null;
    }
}
