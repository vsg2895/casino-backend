<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\Newsletter;
use App\Models\SendgridKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * No "Send test" button may add anybody to the newsletter list.
 *
 * The list is a record of people who ASKED to hear from a site, and only two
 * things may add to it: a public subscribe form, and the import in the
 * Newsletter section. Every admin test button used to call
 * `Newsletter::firstOrCreate()` to get a model with real unsubscribe tokens, so
 * checking a template silently signed the test address up — and the list filled
 * with colleagues and developers who then received real campaigns.
 *
 * These tests cover all five buttons at once, because the regression is the
 * kind that returns one controller at a time.
 *
 * NO REAL EMAIL IS SENT: Mail::fake() throughout.
 */
class TestEmailsDoNotSubscribeTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string ADDRESS = 'reviewer@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->actingAsAdmin();
    }

    public function test_the_promotion_template_test_does_not_subscribe_anyone(): void
    {
        [$site] = $this->siteWithKey();

        $this->postJson("/api/v1/admin/sites/{$site->id}/promotion-email/test", ['to' => self::ADDRESS])
            ->assertSuccessful();

        $this->assertSame(0, Newsletter::withTrashed()->count(), 'a layout check is not a sign-up');
    }

    public function test_the_subscription_template_test_does_not_subscribe_anyone(): void
    {
        [$site] = $this->siteWithKey();

        $this->postJson("/api/v1/admin/sites/{$site->id}/email-template/test", ['to' => self::ADDRESS])
            ->assertSuccessful();

        $this->assertSame(0, Newsletter::withTrashed()->count());
    }

    public function test_the_verify_template_test_does_not_subscribe_anyone(): void
    {
        [$site] = $this->siteWithKey();

        $this->postJson("/api/v1/admin/sites/{$site->id}/verify-email/test", ['to' => self::ADDRESS])
            ->assertSuccessful();

        $this->assertSame(0, Newsletter::withTrashed()->count());
    }

    public function test_an_address_already_on_the_list_is_reused_not_duplicated(): void
    {
        /*
         * The case the old code was protecting: a test sent to a REAL subscriber
         * must still carry that subscriber's own tokens, so the unsubscribe link
         * and the one-click header work end to end. Reusing the row is fine —
         * creating one is not.
         */
        [$site] = $this->siteWithKey();
        $existing = Newsletter::create(['site_id' => $site->id, 'email' => self::ADDRESS]);

        $this->postJson("/api/v1/admin/sites/{$site->id}/promotion-email/test", ['to' => self::ADDRESS])
            ->assertSuccessful();

        $this->assertSame(1, Newsletter::count());
        $this->assertSame(
            $existing->promotion_unsubscribe_token,
            $existing->fresh()->promotion_unsubscribe_token,
            'an existing subscriber must keep the token its live emails already carry',
        );
    }

    public function test_the_public_subscribe_form_still_adds_people(): void
    {
        // The guard must not break the one path that is supposed to write.
        [$site, $key] = $this->siteWithKey();

        $this->postJson($this->publicBase($site) . '/newsletter', ['email' => 'reader@example.com'], $this->siteHeaders($key))
            ->assertSuccessful();

        $this->assertSame(1, Newsletter::where('email', 'reader@example.com')->count());
    }
}
