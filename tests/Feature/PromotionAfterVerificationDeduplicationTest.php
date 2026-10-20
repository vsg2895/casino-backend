<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendRegistrationPromotionJob;
use App\Jobs\SendVerificationPromotionJob;
use App\Mail\PostVerificationPromotionEmail;
use App\Models\EmailSchedule;
use App\Models\ForumUser;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionSend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ONE PERSON, ONE POST-VERIFICATION PROMOTION.
 *
 * The same human can reach this template by two entirely separate routes —
 * subscribing to the newsletter, and registering an account — and they are
 * different rows, in different tables, each with its own claim column that
 * cannot see the other. So "we already mailed this subscriber" and "we already
 * mailed this member" were both true and neither answered the question that
 * matters: has this ADDRESS been mailed.
 *
 * Both orderings are tested, because the bug was one-directional. The
 * registration job has consulted the shared send log since it was written, so
 * subscribe-then-register was already blocked; the newsletter job did not, so
 * register-then-subscribe sent the identical email a second time.
 *
 * The last two tests guard the parts that are easy to get wrong in the other
 * direction — blocking somebody who should be mailed. A FAILED send must leave
 * the address eligible (one outage must not consume a person's single send),
 * and a different address must be untouched.
 */
class PromotionAfterVerificationDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    /** The address that exists in both audiences. */
    private const string SHARED = 'both@example.test';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();

        config()->set('mail.public_mailer', 'sendgrid');
        config()->set('mail.mailers.sendgrid', ['transport' => 'array', 'key' => 'SG.test-key']);

        $config = VerificationPromotionEmail::current();
        $config->update([
            'active'          => true,
            'delay_minutes'   => 2,
            'provider'        => EmailSchedule::PROVIDER_SENDGRID_ENV,
            'sendgrid_key_id' => null,
            'mailgun_key_id'  => null,
            'from_email'      => 'offers@example.test',
            'from_name'       => 'Offers',
        ]);
    }

    /** A verified subscriber whose delay has elapsed. */
    private function subscriber(string $email = self::SHARED): Newsletter
    {
        $newsletter = new Newsletter([
            'site_id'  => $this->site->id,
            'email'    => $email,
            'verified' => true,
        ]);
        $newsletter->save();

        // `verified_at` is not fillable — written the way the model intends.
        Newsletter::whereKey($newsletter->id)->update([
            'verified_at' => Carbon::now()->subMinutes(10),
        ]);

        return $newsletter->refresh();
    }

    /** A verified, consenting member whose delay has elapsed. */
    private function member(string $email = self::SHARED): ForumUser
    {
        $member = ForumUser::create([
            'site_id'      => $this->site->id,
            'display_name' => 'Member',
            'email'        => $email,
            'password'     => bcrypt('secret'),
        ]);

        // `status` and `slug` are deliberately not fillable — a registration
        // POST must not set them — so they are written with forceFill.
        $member->forceFill([
            'slug'                           => 'member-' . md5($email),
            'status'                         => ForumUser::STATUS_ACTIVE,
            'email_verified_at'              => Carbon::now()->subMinutes(10),
            'marketing_consent_at'           => Carbon::now()->subMinutes(10),
            'verification_promotion_sent_at' => null,
        ])->save();

        return $member->refresh();
    }

    // ── The two orderings ────────────────────────────────────────────────────

    public function test_registering_after_subscribing_does_not_send_the_promotion_twice(): void
    {
        Mail::fake();

        $newsletter = $this->subscriber();
        $member = $this->member();

        // Subscriber first: they get it.
        SendVerificationPromotionJob::dispatchSync($newsletter->id);
        // Then the same person's registered account becomes eligible.
        SendRegistrationPromotionJob::dispatchSync($member->id);

        Mail::assertSent(PostVerificationPromotionEmail::class, 1);
        $this->assertSame(
            1,
            VerificationPromotionSend::where('email', self::SHARED)
                ->where('status', VerificationPromotionSend::STATUS_SUCCESS)
                ->count(),
            'exactly one successful send should be recorded for the address',
        );
    }

    public function test_subscribing_after_registering_does_not_send_the_promotion_twice(): void
    {
        // THE REGRESSION. This is the direction that was broken: the newsletter
        // job had no cross-audience check, so the member's send was invisible
        // to it and the same address was mailed a second time.
        Mail::fake();

        $member = $this->member();
        $newsletter = $this->subscriber();

        // Member first this time.
        SendRegistrationPromotionJob::dispatchSync($member->id);
        // Then the same person subscribes and verifies.
        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertSent(PostVerificationPromotionEmail::class, 1);
        $this->assertSame(
            1,
            VerificationPromotionSend::where('email', self::SHARED)
                ->where('status', VerificationPromotionSend::STATUS_SUCCESS)
                ->count(),
            'exactly one successful send should be recorded for the address',
        );
    }

    public function test_the_blocked_side_is_retired_from_the_sweep(): void
    {
        // Skipping without claiming would leave the sweep re-queueing this
        // subscriber every minute, for ever, for a decision that can never come
        // out differently.
        Mail::fake();

        $member = $this->member();
        $newsletter = $this->subscriber();

        SendRegistrationPromotionJob::dispatchSync($member->id);
        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        $this->assertNotNull(
            $newsletter->refresh()->verification_promotion_sent_at,
            'the blocked subscriber is claimed so the sweep stops reconsidering them',
        );
    }

    public function test_the_blocked_side_writes_no_second_history_row(): void
    {
        // The admin history must show ONE email for this address, not one email
        // and one "we decided not to send" row.
        Mail::fake();

        $member = $this->member();
        $newsletter = $this->subscriber();

        SendRegistrationPromotionJob::dispatchSync($member->id);
        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        $this->assertSame(
            1,
            VerificationPromotionSend::where('email', self::SHARED)->count(),
            'the skipped send records nothing at all',
        );
    }

    // ── Guards against blocking somebody who SHOULD be mailed ────────────────

    public function test_a_failed_send_leaves_the_address_eligible(): void
    {
        // A failed attempt means the person received nothing. If the gate
        // counted it, one transport outage would permanently consume their
        // single send.
        $newsletter = $this->subscriber();

        VerificationPromotionSend::create([
            'site_id'       => $this->site->id,
            'newsletter_id' => null,
            'source'        => VerificationPromotionSend::SOURCE_REGISTRATION,
            'email'         => self::SHARED,
            'status'        => VerificationPromotionSend::STATUS_FAILED,
            'sent_at'       => Carbon::now()->subMinute(),
        ]);

        $this->assertFalse(
            VerificationPromotionSend::alreadySentTo($this->site->id, self::SHARED),
            'a failed attempt must not count as "already received"',
        );

        Mail::fake();
        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertSent(PostVerificationPromotionEmail::class, 1);
    }

    public function test_a_different_address_is_unaffected(): void
    {
        Mail::fake();

        $member = $this->member();
        $other = $this->subscriber('someone-else@example.test');

        SendRegistrationPromotionJob::dispatchSync($member->id);
        SendVerificationPromotionJob::dispatchSync($other->id);

        // Two different people, two emails. The gate is per address, and must
        // not become "nobody else gets one once somebody has".
        Mail::assertSent(PostVerificationPromotionEmail::class, 2);
    }

    public function test_the_gate_is_scoped_to_the_site(): void
    {
        // A send recorded against ANOTHER site must not block this one: the
        // template is rendered per site, so two brands are two different emails.
        $otherSite = Site::factory()->create();

        VerificationPromotionSend::create([
            'site_id'       => $otherSite->id,
            'newsletter_id' => null,
            'source'        => VerificationPromotionSend::SOURCE_NEWSLETTER,
            'email'         => self::SHARED,
            'status'        => VerificationPromotionSend::STATUS_SUCCESS,
            'sent_at'       => Carbon::now()->subMinute(),
        ]);

        $this->assertFalse(
            VerificationPromotionSend::alreadySentTo($this->site->id, self::SHARED),
        );
        $this->assertTrue(
            VerificationPromotionSend::alreadySentTo($otherSite->id, self::SHARED),
        );
    }

    // ── The simultaneous case ────────────────────────────────────────────────

    public function test_a_job_holding_the_address_lock_blocks_the_other_audience(): void
    {
        /*
         * The race the send log alone cannot close: it is written only AFTER the
         * mail goes out, so two jobs starting together both look, both see
         * nothing, and both send. The sweep queues exactly that pair in one run
         * and production runs two queue workers.
         *
         * Simulated by taking the lock first and leaving it held — which is
         * precisely the state the other worker is in mid-send.
         */
        Mail::fake();

        $newsletter = $this->subscriber();
        $lock = Cache::lock(
            VerificationPromotionSend::lockKeyFor($this->site->id, self::SHARED),
            VerificationPromotionSend::SEND_LOCK_SECONDS,
        );

        $this->assertTrue($lock->get(), 'the test must own the lock for this to prove anything');

        try {
            SendVerificationPromotionJob::dispatchSync($newsletter->id);

            Mail::assertNothingSent();
            $this->assertNull(
                $newsletter->refresh()->verification_promotion_sent_at,
                'losing the lock must NOT claim — the next sweep has to reconsider them',
            );
        } finally {
            $lock->release();
        }

        // And once the other job is done, the next attempt proceeds normally.
        SendVerificationPromotionJob::dispatchSync($newsletter->id);
        Mail::assertSent(PostVerificationPromotionEmail::class, 1);
    }

    public function test_the_two_jobs_contend_for_the_same_lock_key(): void
    {
        // If the keys differed the lock would be decorative. Asserted directly
        // because the failure is invisible — both jobs would simply succeed.
        $this->assertSame(
            VerificationPromotionSend::lockKeyFor($this->site->id, self::SHARED),
            VerificationPromotionSend::lockKeyFor($this->site->id, strtoupper(self::SHARED)),
            'casing must not split one address into two locks',
        );
    }
}
