<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PostVerificationPromotionEmail;
use App\Models\EmailSchedule;
use App\Models\ForumUser;
use App\Models\Newsletter;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionSend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The WHOLE registration promotion path, driven through the real endpoints.
 *
 * The eligibility rules already have their own tests. What was missing is a
 * test that walks the path a person actually walks — POST the registration form
 * with the box ticked, click the link in the confirmation email, wait out the
 * delay, let the sweep run — and asserts an email leaves at the end of it.
 *
 * Written because that exact path was reported as silent in production while
 * the newsletter path was working. A test like this cannot see a production
 * misconfiguration, but it can say definitively whether the CODE is the
 * problem — and so turn "it does not work" into a short list of things to
 * check on the server.
 */
class RegistrationPromotionEndToEndTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string EMAIL = 'member@example.test';

    /** Set by registerAndVerify(), which is what creates the site. */
    private ?\App\Models\Site $site = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.public_mailer', 'sendgrid');
        config()->set('mail.mailers.sendgrid', ['transport' => 'array', 'key' => 'SG.test-key']);

        VerificationPromotionEmail::current()->update([
            'active'          => true,
            'delay_minutes'   => 2,
            'provider'        => EmailSchedule::PROVIDER_SENDGRID_ENV,
            'sendgrid_key_id' => null,
            'mailgun_key_id'  => null,
            'from_email'      => 'offers@example.test',
            'from_name'       => 'Offers',
        ]);
    }

    /**
     * Register through the real endpoint with the box ticked, then confirm
     * through the real signed link — exactly what the form and the email do.
     */
    private function registerAndVerify(bool $consent = true): ForumUser
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        [$site, $key] = $this->siteWithKey(['accounts_enabled' => true]);
        $this->site = $site;

        $this->postJson($this->publicBase($site) . '/forum/members/register', [
            'display_name'      => 'Vladimir',
            'email'             => self::EMAIL,
            'password'          => 'secret123',
            'marketing_consent' => $consent,
            // The honeypot: required, and must be empty.
            'website'           => '',
        ], $this->siteHeaders($key))->assertCreated();

        $member = ForumUser::where('email', self::EMAIL)->firstOrFail();

        // The confirmation link is signed; build it the way the mail does.
        $this->post(URL::signedRoute('forum.verify', [
            'site'   => $site->slug,
            'member' => $member->id,
        ]), [], $this->siteHeaders($key))->assertOk();

        return $member->refresh();
    }

    public function test_registering_with_the_box_ticked_sends_the_promotion_after_the_delay(): void
    {
        Mail::fake();

        $member = $this->registerAndVerify();

        $this->assertNotNull($member->marketing_consent_at, 'the ticked box must be stamped');
        $this->assertNotNull($member->email_verified_at, 'the signed link must confirm the address');

        // Before the delay: nothing.
        $this->artisan('promotions:dispatch-verification')->assertSuccessful();
        // NOT assertNothingSent(): registering correctly sends the confirmation
        // email, and asserting silence here would fail on the feature working.
        Mail::assertNotSent(PostVerificationPromotionEmail::class);

        // Two minutes later: the sweep picks them up and the mail goes out.
        $this->travel(3)->minutes();
        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Mail::assertSent(PostVerificationPromotionEmail::class, 1);
        $this->assertNotNull($member->refresh()->verification_promotion_sent_at);
        $this->assertSame(
            VerificationPromotionSend::SOURCE_REGISTRATION,
            VerificationPromotionSend::where('email', self::EMAIL)->value('source'),
            'and it is logged against the registration flow, not the newsletter one',
        );
    }

    public function test_an_unticked_box_sends_nothing(): void
    {
        // Registering is not consenting. This is the gate that must never relax.
        Mail::fake();

        $this->registerAndVerify(consent: false);
        $this->travel(3)->minutes();
        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Mail::assertNotSent(PostVerificationPromotionEmail::class);
    }

    public function test_the_same_address_subscribing_first_is_why_registration_can_be_silent(): void
    {
        /*
         * THE most likely explanation for "the newsletter path works and the
         * registration path does not" when both are used with one address.
         *
         * The cross-audience gate is deliberate — one person, one promotion —
         * but from the outside it looks exactly like the registration flow
         * being broken. This test exists so that behaviour is recorded as
         * intended rather than rediscovered as a bug.
         */
        Mail::fake();

        $member = $this->registerAndVerify();

        // The address already received it through the newsletter, on this site.
        $newsletter = new Newsletter([
            'site_id'  => $this->site->id,
            'email'    => self::EMAIL,
            'verified' => true,
        ]);
        $newsletter->save();

        VerificationPromotionSend::create([
            'site_id'       => $member->site_id,
            'newsletter_id' => null,
            'source'        => VerificationPromotionSend::SOURCE_NEWSLETTER,
            'email'         => self::EMAIL,
            'status'        => VerificationPromotionSend::STATUS_SUCCESS,
            'sent_at'       => Carbon::now(),
        ]);

        $this->travel(3)->minutes();
        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Mail::assertNotSent(PostVerificationPromotionEmail::class);
        $this->assertNotNull(
            $member->refresh()->verification_promotion_sent_at,
            'and the member is claimed, so the sweep stops reconsidering them every minute',
        );
    }
}
