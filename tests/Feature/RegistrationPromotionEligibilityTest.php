<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumUser;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Who gets the post-verification promotion after REGISTERING, and when.
 *
 * The timing is the whole feature, and it is the part that cannot be eyeballed:
 * a member who verifies at 00:02 with the delay set to 2 minutes must be picked
 * at 00:04 and not before. Everything else here guards a way of mailing somebody
 * who should not be mailed, which is the failure mode that matters — an email
 * that goes out cannot be recalled.
 */
class RegistrationPromotionEligibilityTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();

        $config = VerificationPromotionEmail::current();
        $config->forceFill(['active' => true, 'delay_minutes' => 2])->save();
    }

    private function member(string $email, ?CarbonInterface $verifiedAt = null): ForumUser
    {
        $member = ForumUser::create([
            'site_id'      => $this->site->id,
            'display_name' => 'Member ' . $email,
            'email'        => $email,
            'password'     => bcrypt('secret'),
        ]);

        // `status` and `slug` are deliberately not fillable — a registration
        // POST must not set them — so they are written the way the model intends.
        $member->forceFill([
            'slug'              => str_replace(['@', '.'], '-', $email),
            'status'            => ForumUser::STATUS_ACTIVE,
            'email_verified_at' => $verifiedAt,
            // Consent is a precondition for every test below except the one
            // that exists to prove it is required.
            'marketing_consent_at' => Carbon::now(),
            // The migration's backfill marks pre-existing members as claimed;
            // a member created in a test is new, so this is explicitly open.
            'verification_promotion_sent_at' => null,
        ])->save();

        return $member->refresh();
    }

    /** The sweep's own definition of eligibility, as the command runs it. */
    private function eligibleCount(): int
    {
        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        // The command reports what it queued; counting the rows it would pick is
        // the same query, run here so the assertion names a number rather than
        // parsing console output.
        $cutoff = Carbon::now()->subMinutes((int) VerificationPromotionEmail::current()->delay_minutes);

        return ForumUser::query()
            ->whereNull('verification_promotion_sent_at')
            ->whereNotNull('email_verified_at')
            ->where('email_verified_at', '<=', $cutoff)
            // Mirrors the sweep exactly, consent included. Leaving it out is
            // how this helper first reported a non-consenting member as
            // eligible — the assertion was right and the helper was wrong.
            ->whereNotNull('marketing_consent_at')
            ->where('status', '!=', ForumUser::STATUS_BANNED)
            ->count();
    }

    public function test_the_clock_starts_at_the_verification_click(): void
    {
        /*
         * The example this feature was specified with: verified at 00:02, delay
         * 2 minutes, eligible at 00:04. Asserted from both sides of the
         * boundary, because a rule that is only checked in the middle of its
         * range is a rule whose edge is untested.
         */
        $member = $this->member('timing@example.test', Carbon::now());

        $this->assertSame(0, $this->eligibleCount(), 'not eligible the moment they verify');

        // One second short of the delay.
        $member->forceFill(['email_verified_at' => Carbon::now()->subSeconds(119)])->save();
        $this->assertSame(0, $this->eligibleCount(), 'not eligible one second early');

        // One second past it.
        $member->forceFill(['email_verified_at' => Carbon::now()->subSeconds(121)])->save();
        $this->assertSame(1, $this->eligibleCount(), 'eligible once the delay has elapsed');
    }

    public function test_an_unverified_registration_is_never_eligible(): void
    {
        // Registered, never clicked the link. The commonest state in the table
        // and the one that must never be mailed.
        $this->member('never@example.test', null);

        $this->assertSame(0, $this->eligibleCount());
    }

    public function test_a_banned_member_is_never_eligible(): void
    {
        $member = $this->member('banned@example.test', Carbon::now()->subMinutes(10));
        $member->forceFill(['status' => ForumUser::STATUS_BANNED])->save();

        $this->assertSame(0, $this->eligibleCount(), 'marketing must not follow somebody off the site');
    }

    public function test_an_opted_out_address_is_never_eligible(): void
    {
        $this->member('gone@example.test', Carbon::now()->subMinutes(10));

        Unsubscribe::record($this->site->id, 'gone@example.test', Unsubscribe::TYPE_PROMOTION_AFTER_VERIFICATION);

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        // The sweep's own exclusion, not the job's re-check: an opted-out
        // address must not even be queued.
        $cutoff = Carbon::now()->subMinutes(2);
        $queued = ForumUser::query()
            ->whereNull('verification_promotion_sent_at')
            ->whereNotNull('email_verified_at')
            ->where('email_verified_at', '<=', $cutoff)
            ->whereNotExists(function ($query): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'forum_users.email')
                    ->whereColumn('unsubscribes.site_id', 'forum_users.site_id');
            })
            ->count();

        $this->assertSame(0, $queued);
    }

    public function test_every_member_gets_an_opt_out_token_that_resolves(): void
    {
        /*
         * The unsubscribe link in this email is the member's only way out, and
         * it resolves through a token that has to exist before any email can
         * reference it. A member with no token would receive marketing with a
         * dead opt-out.
         */
        $member = $this->member('token@example.test', Carbon::now());

        $this->assertSame(64, strlen((string) $member->verification_promotion_unsubscribe_token));

        $found = ForumUser::findByUnsubscribeToken((string) $member->verification_promotion_unsubscribe_token);
        $this->assertNotNull($found);
        $this->assertSame($member->id, $found->id);

        $this->assertNull(ForumUser::findByUnsubscribeToken(str_repeat('x', 64)), 'an unknown token resolves to nobody');
        $this->assertNull(ForumUser::findByUnsubscribeToken(''), 'an empty token never matches');
    }

    public function test_the_token_survives_later_saves(): void
    {
        // It is in delivered email forever; regenerating it would break the
        // opt-out in every message already sent.
        $member = $this->member('stable@example.test', Carbon::now());
        $original = (string) $member->verification_promotion_unsubscribe_token;

        $member->forceFill(['display_name' => 'Renamed'])->save();

        $this->assertSame($original, (string) $member->refresh()->verification_promotion_unsubscribe_token);
    }

    public function test_a_member_who_did_not_consent_is_never_eligible(): void
    {
        /*
         * The gate the consent checkbox exists for. Registering an account is
         * not agreeing to be marketed to, and a member who left the box alone
         * has `marketing_consent_at = null` — the default, and the state of
         * every member who registered before the box existed.
         */
        $member = $this->member('noconsent@example.test', Carbon::now()->subMinutes(10));
        $member->forceFill(['marketing_consent_at' => null])->save();

        $this->assertSame(0, $this->eligibleCount());
    }

    public function test_registering_records_consent_only_when_asked_for(): void
    {
        /*
         * The register endpoint carries `throttle:forum-register`, and three
         * registrations in one test trip it. Disabled here rather than split
         * across three tests: the three cases are one rule — ticked, absent,
         * explicitly false — and reading them together is the point.
         */
        $this->withoutMiddleware(ThrottleRequests::class);

        // `accounts_enabled`, because ForumAuthController::site() 404s without
        // it — these endpoints follow the accounts switch, not the board's.
        [$site, $key] = $this->siteWithKey(['accounts_enabled' => true]);
        $base = $this->publicBase($site);
        $headers = $this->siteHeaders($key);

        // Ticked.
        $this->postJson("{$base}/forum/members/register", [
            'display_name'      => 'Yes Please',
            'email'             => 'yes@example.test',
            'password'          => 'secret123',
            'marketing_consent' => true,
            // The honeypot. Required and must be EMPTY — the endpoint rejects a
            // submission that filled it, and one that omits it entirely.
            'website'           => '',
        ], $headers)->assertCreated();

        $this->assertNotNull(
            ForumUser::where('email', 'yes@example.test')->first()?->marketing_consent_at,
            'a ticked box is stamped with the moment it was given',
        );

        // Absent — the common case, and it must not fail the registration.
        $this->postJson("{$base}/forum/members/register", [
            'display_name' => 'No Thanks',
            'email'        => 'no@example.test',
            'password'     => 'secret123',
            'website'      => '',
        ], $headers)->assertCreated();

        $this->assertNull(
            ForumUser::where('email', 'no@example.test')->first()?->marketing_consent_at,
            'an absent key means the same as an unticked box',
        );

        // Explicitly false.
        $this->postJson("{$base}/forum/members/register", [
            'display_name'      => 'Explicit No',
            'email'             => 'false@example.test',
            'password'          => 'secret123',
            'marketing_consent' => false,
            'website'           => '',
        ], $headers)->assertCreated();

        $this->assertNull(ForumUser::where('email', 'false@example.test')->first()?->marketing_consent_at);
    }
}
