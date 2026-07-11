<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Double opt-in verify endpoint: POST /api/v1/verify/{token}. Keyless — the
 * subscriber's opaque subscription token is the credential. Idempotent and
 * always returns ok so it never reveals whether a token exists.
 */
class EmailVerificationTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    public function test_clicking_the_verify_link_marks_the_subscriber_verified(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'fan@example.com',
            'verified' => false,
        ]);

        $this->postJson('/api/v1/verify/' . $subscriber->unsubscribe_token)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue($subscriber->fresh()->verified);
    }

    public function test_already_verified_stays_verified(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'done@example.com',
            'verified' => true,
        ]);

        $this->postJson('/api/v1/verify/' . $subscriber->unsubscribe_token)->assertOk();

        $this->assertTrue($subscriber->fresh()->verified);
    }

    public function test_unknown_token_is_a_no_op_but_still_ok(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'other@example.com',
            'verified' => false,
        ]);

        $this->postJson('/api/v1/verify/' . str_repeat('z', 64))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertFalse($subscriber->fresh()->verified);
    }

    public function test_promotion_token_does_not_verify(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'promo@example.com',
            'verified' => false,
        ]);

        // The verify endpoint only matches the subscription token, never the
        // separate promotion-stream token.
        $this->postJson('/api/v1/verify/' . $subscriber->promotion_unsubscribe_token)->assertOk();

        $this->assertFalse($subscriber->fresh()->verified);
    }
}
