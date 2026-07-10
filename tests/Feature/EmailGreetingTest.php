<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use App\Services\VerifyEmailService;
use App\Support\EmailGreeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The optional "Dear {name}," greeting: present when a subscriber (or admin
 * test) supplies a name, absent otherwise — across all three email types.
 */
class EmailGreetingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sendgrid.from_domain', 'mail.test');
    }

    public function test_greeting_helper_formats_or_omits(): void
    {
        $this->assertSame('Dear Jane Doe,', EmailGreeting::line('Jane Doe'));
        $this->assertSame('Dear Jane,', EmailGreeting::line('  Jane  '));
        $this->assertSame('', EmailGreeting::line(null));
        $this->assertSame('', EmailGreeting::line('   '));
    }

    public function test_verify_email_includes_greeting_only_with_a_name(): void
    {
        [$site] = $this->siteWithKey();
        $service = app(VerifyEmailService::class);

        $named = Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.com', 'full_name' => 'Jane']);
        $this->assertStringContainsString('Dear Jane,', $service->mailForSubscriber($site, $named)->render());

        $anon = Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.com']);
        $this->assertStringNotContainsString('Dear', $service->mailForSubscriber($site, $anon)->render());
    }

    public function test_subscription_email_includes_greeting_only_with_a_name(): void
    {
        [$site] = $this->siteWithKey();
        $service = app(SubscriptionEmailService::class);

        $named = Newsletter::create(['site_id' => $site->id, 'email' => 'c@example.com', 'full_name' => 'Bob']);
        $this->assertStringContainsString('Dear Bob,', $service->mailForSubscriber($site, $named)->render());

        $anon = Newsletter::create(['site_id' => $site->id, 'email' => 'd@example.com']);
        $this->assertStringNotContainsString('Dear', $service->mailForSubscriber($site, $anon)->render());
    }

    public function test_promotion_email_includes_greeting_only_with_a_name(): void
    {
        [$site] = $this->siteWithKey();
        $service = app(PromotionEmailService::class);
        $template = $site->promotionEmailOrDefault();

        $named = Newsletter::create(['site_id' => $site->id, 'email' => 'e@example.com', 'full_name' => 'Al']);
        $this->assertStringContainsString('Dear Al,', $service->mailForSubscriber($site, $template, $named)->render());

        $anon = Newsletter::create(['site_id' => $site->id, 'email' => 'f@example.com']);
        $this->assertStringNotContainsString('Dear', $service->mailForSubscriber($site, $template, $anon)->render());
    }

    public function test_admin_test_send_greets_with_the_provided_name(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        config()->set('mail.test_mailer', 'array');
        \Illuminate\Support\Facades\Mail::fake();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/verify-email/test",
            ['to' => 'tester@example.com', 'name' => 'Sam'],
        )->assertOk();

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\VerifyEmailMail::class,
            fn (\App\Mail\VerifyEmailMail $mail): bool => str_contains($mail->render(), 'Dear Sam,'),
        );
    }
}
