<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunReceiver;
use App\Models\Site;
use App\Models\VerificationPromotionSend;
use App\Models\VerifyEmailSend;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Every remaining admin date window means the OPERATOR'S day.
 *
 * The four screens here filter a UTC column with a calendar day picked from a
 * local calendar. Before this, the server read that day as UTC midnight, so an
 * operator east of Greenwich lost the first hours of their own day out of every
 * window — while the tables, which render in local time, still showed those
 * rows on the day the filter claimed was empty.
 *
 * `WarmupSendRecipient::scopeSentUntil()` carried a comment naming this exact
 * behaviour as known and accepted. It is not accepted any more.
 *
 * One instant is used throughout: 21:30 UTC on the 19th, which is 00:30 on the
 * 20th in UTC+3. Every assertion is that it belongs to the operator's 20th and
 * not to their 19th — plus, for each screen, that no timezone still means UTC,
 * because that is what keeps every existing caller working.
 *
 * @see UniOneReceiverDateFilterTest
 * @see ForumArticleDateFilterTest
 */
class HistoryDateFilterTimezoneTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** UTC+3 in October 2026 — EEST runs until the 25th. */
    private const string TZ = 'Europe/Tallinn';

    private Site $site;

    /** 00:30 on the 20th for the operator; the 19th in UTC. */
    private Carbon $instant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->instant = Carbon::parse('2026-10-19 21:30:00', 'UTC');

        $this->actingAsAdmin();
    }

    /**
     * A warmup recipient and the run it belongs to.
     *
     * `warmup_send_id` is NOT NULL — a recipient row only exists as part of a
     * run — so the parent has to be real rather than stubbed away.
     */
    private function warmupRecipient(string $email, Carbon $sentAt): void
    {
        $send = WarmupSend::create([
            'site_id'  => $this->site->id,
            'template' => 'intro',
        ]);

        WarmupSendRecipient::create([
            'warmup_send_id' => $send->id,
            'site_id'        => $this->site->id,
            'email'          => $email,
            'template'       => 'intro',
            'status'         => 'sent',
            'sent_at'        => $sentAt,
        ]);
    }

    /**
     * Rows the listing returns for this window, checked against the screen's
     * own COUNT endpoint.
     *
     * The listings are simple-paginated and carry no `meta.total`; the screen
     * sizes itself from `{$url}/count`. Both are asserted, because both apply
     * the window: a listing and a count that disagreed would show a paginator
     * that does not match its rows.
     */
    private function total(string $url, array $query): int
    {
        $qs = http_build_query($query);
        $rows = count($this->getJson("{$url}?{$qs}")->assertOk()->json('data'));
        $counted = (int) $this->getJson("{$url}/count?{$qs}")->assertOk()->json('total');

        $this->assertSame($rows, $counted, "{$url}: the listing and its count must apply the same window");

        return $rows;
    }

    /**
     * The three assertions every one of these screens has to satisfy.
     *
     * Shared rather than written out four times: the rule is one rule, and four
     * copies would be four chances for one screen to quietly drift.
     */
    private function assertWindowFollowsTheOperator(string $url, string $fromKey, string $toKey): void
    {
        $this->assertSame(
            1,
            $this->total($url, [$fromKey => '2026-10-20', 'tz' => self::TZ]),
            "{$url}: the operator sees this row on the 20th, so their filter for the 20th must find it",
        );

        $this->assertSame(
            0,
            $this->total($url, [$toKey => '2026-10-19', 'tz' => self::TZ]),
            "{$url}: in the operator's zone this did not happen on the 19th",
        );

        $this->assertSame(
            1,
            $this->total($url, [$fromKey => '2026-10-19']),
            "{$url}: without a timezone the bounds must stay UTC, as they always were",
        );
    }

    public function test_verify_email_history_follows_the_operators_day(): void
    {
        VerifyEmailSend::create([
            'site_id' => $this->site->id,
            'email'   => 'reader@example.test',
            'status'  => 'success',
            'sent_at' => $this->instant,
        ]);

        $this->assertWindowFollowsTheOperator('/api/v1/admin/verify-email-sends', 'sent_from', 'sent_to');
    }

    public function test_post_verification_history_follows_the_operators_day(): void
    {
        VerificationPromotionSend::create([
            'site_id' => $this->site->id,
            'source'  => VerificationPromotionSend::SOURCE_NEWSLETTER,
            'email'   => 'reader@example.test',
            'status'  => VerificationPromotionSend::STATUS_SUCCESS,
            'sent_at' => $this->instant,
        ]);

        $this->assertWindowFollowsTheOperator('/api/v1/admin/verification-promotion/history', 'sent_from', 'sent_to');
    }

    public function test_warmup_history_follows_the_operators_day(): void
    {
        $this->warmupRecipient('reader@example.test', $this->instant);

        $this->assertWindowFollowsTheOperator('/api/v1/admin/warmup-emails/history', 'sent_from', 'sent_to');
    }

    public function test_mailgun_receivers_follow_the_operators_day(): void
    {
        $receiver = MailgunReceiver::create(['email' => 'reader@example.test']);
        $receiver->forceFill(['last_sent_at' => $this->instant])->save();

        $this->assertWindowFollowsTheOperator('/api/v1/admin/mailgun-receivers', 'last_sent_from', 'last_sent_to');
    }

    public function test_warmups_upper_bound_still_covers_its_whole_day(): void
    {
        /*
         * Warmup's upper bound used the half-open `< next midnight` form, which
         * existed to stop a bare date string being read as that day's 00:00:00
         * and dropping the whole day. It is now `<= 23:59:59` of the resolved
         * day, which is exact for a `timestamp(0)` column — this holds that
         * line, because getting it wrong would silently drop the last second of
         * every window and nobody would look here for it.
         */
        // The final second of the operator's 20th: 20:59:59 UTC.
        $this->warmupRecipient('edge@example.test', Carbon::parse('2026-10-20 20:59:59', 'UTC'));

        $this->assertSame(
            1,
            $this->total('/api/v1/admin/warmup-emails/history', [
                'sent_from' => '2026-10-20',
                'sent_to'   => '2026-10-20',
                'tz'        => self::TZ,
            ]),
        );
    }

    public function test_an_unrecognised_timezone_falls_back_to_utc_on_history_screens(): void
    {
        /*
         * These are read-only logs whose other filters deliberately tolerate a
         * stale bookmark, so a bad zone degrades to UTC rather than 422-ing the
         * page. The receivers and discussions screens reject it instead —
         * their filters drive who gets mailed, where a silent shift would be
         * worse than an error.
         */
        VerifyEmailSend::create([
            'site_id' => $this->site->id,
            'email'   => 'reader@example.test',
            'status'  => 'success',
            'sent_at' => $this->instant,
        ]);

        $this->assertSame(
            1,
            $this->total('/api/v1/admin/verify-email-sends', ['sent_from' => '2026-10-19', 'tz' => 'Mars/Olympus']),
        );
    }
}
