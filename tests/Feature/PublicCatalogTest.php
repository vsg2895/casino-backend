<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessNewsletterSubscription;
use App\Jobs\SendNewsletterWelcomeEmail;
use App\Mail\NewsletterSubscribedMail;
use App\Models\Casino;
use App\Models\Category;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function attachedCasino(Site $site): Casino
    {
        $casino = Casino::factory()->create();
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test/go', 'active' => true]);

        return $casino;
    }

    // ── Categories — scoped to the site ───────────────────────────────────

    public function test_categories_index_only_lists_categories_with_casinos_on_the_site(): void
    {
        [$site, $key] = $this->siteWithKey();

        $onSite  = Category::factory()->create(['name' => 'On Site']);
        $offSite = Category::factory()->create(['name' => 'Off Site']);

        $this->attachedCasino($site)->categories()->attach($onSite->id);
        // offSite category is linked to a casino NOT attached to this site
        Casino::factory()->create()->categories()->attach($offSite->id);

        $names = collect($this->getJson($this->publicBase($site) . '/categories', $this->siteHeaders($key))->json('data'))->pluck('name');

        $this->assertTrue($names->contains('On Site'));
        $this->assertFalse($names->contains('Off Site'));
    }

    public function test_category_show_returns_only_this_sites_casinos(): void
    {
        [$site, $key] = $this->siteWithKey();
        $category = Category::factory()->create();

        $mine = $this->attachedCasino($site);
        $mine->categories()->attach($category->id);
        $theirs = Casino::factory()->create();
        $theirs->categories()->attach($category->id); // not attached to $site

        $response = $this->getJson($this->publicBase($site) . '/categories/' . $category->slug, $this->siteHeaders($key))->assertOk();

        $ids = collect($response->json('data.casinos'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_categories_index_is_ordered_by_priority(): void
    {
        [$site, $key] = $this->siteWithKey();

        $low = Category::factory()->create(['name' => 'Zeta', 'sort_order' => 0]);  // highest priority
        $high = Category::factory()->create(['name' => 'Alpha', 'sort_order' => 5]);
        $this->attachedCasino($site)->categories()->attach($low->id);
        $this->attachedCasino($site)->categories()->attach($high->id);

        $names = collect($this->getJson($this->publicBase($site) . '/categories', $this->siteHeaders($key))->json('data'))->pluck('name');

        $this->assertSame(['Zeta', 'Alpha'], $names->all(), 'lower sort_order comes first');
    }

    public function test_category_casinos_are_paginated(): void
    {
        [$site, $key] = $this->siteWithKey();
        $category = Category::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->attachedCasino($site)->categories()->attach($category->id);
        }

        $page1 = $this->getJson($this->publicBase($site) . '/categories/' . $category->slug . '?page=1', $this->siteHeaders($key))->assertOk();
        $this->assertCount(4, $page1->json('data.casinos'));         // PER_PAGE = 4
        $this->assertSame(5, $page1->json('data.meta.total'));
        $this->assertSame(2, $page1->json('data.meta.last_page'));

        $page2 = $this->getJson($this->publicBase($site) . '/categories/' . $category->slug . '?page=2', $this->siteHeaders($key))->assertOk();
        $this->assertCount(1, $page2->json('data.casinos'));
    }

    // ── Special offers — scoped via their casino's site attachment ────────

    public function test_special_offers_only_shows_offers_whose_casino_is_on_the_site(): void
    {
        [$site, $key] = $this->siteWithKey();

        $mineCasino  = $this->attachedCasino($site);
        $otherCasino = Casino::factory()->create(); // not on this site

        $mine  = SpecialOffer::factory()->create(['casino_id' => $mineCasino->id, 'title' => 'Mine']);
        SpecialOffer::factory()->create(['casino_id' => $otherCasino->id, 'title' => 'Theirs']);

        $titles = collect($this->getJson($this->publicBase($site) . '/special-offers', $this->siteHeaders($key))->json('data'))->pluck('title');

        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('Theirs'));

        $this->getJson($this->publicBase($site) . '/special-offers/' . $mine->slug, $this->siteHeaders($key))
            ->assertOk()->assertJsonPath('data.slug', $mine->slug);
    }

    public function test_special_offers_can_be_filtered_by_category(): void
    {
        [$site, $key] = $this->siteWithKey();
        $category = Category::factory()->create(['slug' => 'high-roller']);

        $inCat = $this->attachedCasino($site);
        $inCat->categories()->attach($category->id);
        $outCat = $this->attachedCasino($site); // on the site, but NOT in the category

        SpecialOffer::factory()->create(['casino_id' => $inCat->id, 'title' => 'InCategory']);
        SpecialOffer::factory()->create(['casino_id' => $outCat->id, 'title' => 'OutOfCategory']);

        // Filtered: only offers whose casino is in the category.
        $titles = collect(
            $this->getJson($this->publicBase($site) . '/special-offers?category=high-roller', $this->siteHeaders($key))
                ->assertOk()->json('data')
        )->pluck('title');
        $this->assertEqualsCanonicalizing(['InCategory'], $titles->all());

        // Unfiltered: both.
        $all = collect(
            $this->getJson($this->publicBase($site) . '/special-offers', $this->siteHeaders($key))->json('data')
        )->pluck('title');
        $this->assertTrue($all->contains('InCategory') && $all->contains('OutOfCategory'));
    }

    public function test_special_offers_respects_the_limit_parameter(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = $this->attachedCasino($site);
        SpecialOffer::factory()->count(5)->create(['casino_id' => $casino->id]);

        $this->getJson($this->publicBase($site) . '/special-offers?limit=2', $this->siteHeaders($key))
            ->assertOk()->assertJsonCount(2, 'data');
    }

    // ── Newsletter subscribe ──────────────────────────────────────────────

    public function test_subscribe_queues_the_processing_job_on_the_high_priority_queue(): void
    {
        Bus::fake();
        [$site, $key] = $this->siteWithKey();

        $this->postJson($this->publicBase($site) . '/newsletter', ['email' => 'sub@example.test'], $this->siteHeaders($key))
            ->assertAccepted();

        Bus::assertDispatched(
            ProcessNewsletterSubscription::class,
            fn (ProcessNewsletterSubscription $job): bool => $job->siteId === $site->id
                && $job->email === 'sub@example.test'
                && $job->queue === ProcessNewsletterSubscription::ON_QUEUE,
        );
    }

    public function test_processing_a_new_subscription_stores_it_and_queues_the_welcome_email(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();

        (new ProcessNewsletterSubscription($site->id, 'sub@example.test'))->handle();

        // Stored and attached to the subscribing site.
        $this->assertDatabaseHas('newsletters', ['site_id' => $site->id, 'email' => 'sub@example.test']);

        // Confirmation email is queued on the HIGH-priority queue.
        Queue::assertPushed(
            SendNewsletterWelcomeEmail::class,
            fn (SendNewsletterWelcomeEmail $job): bool => $job->siteId === $site->id
                && $job->email === 'sub@example.test'
                && $job->queue === SendNewsletterWelcomeEmail::ON_QUEUE,
        );
    }

    public function test_duplicate_subscription_is_idempotent_and_does_not_resend_email(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();

        (new ProcessNewsletterSubscription($site->id, 'sub@example.test'))->handle();
        (new ProcessNewsletterSubscription($site->id, 'sub@example.test'))->handle();

        $this->assertSame(1, Newsletter::where(['site_id' => $site->id, 'email' => 'sub@example.test'])->count());
        Queue::assertPushed(SendNewsletterWelcomeEmail::class, 1);
    }

    public function test_resubscribing_after_soft_delete_restores_the_row_and_reconfirms(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();

        $newsletter = Newsletter::create(['site_id' => $site->id, 'email' => 'back@example.test']);
        $newsletter->delete();
        $this->assertSoftDeleted('newsletters', ['id' => $newsletter->id]);

        (new ProcessNewsletterSubscription($site->id, 'back@example.test'))->handle();

        // Restored in place (no duplicate row, no unique-constraint violation).
        $this->assertNotSoftDeleted('newsletters', ['id' => $newsletter->id]);
        $this->assertSame(1, Newsletter::withTrashed()->where('email', 'back@example.test')->count());
        Queue::assertPushed(SendNewsletterWelcomeEmail::class, 1);
    }

    public function test_welcome_email_job_sends_the_confirmation_to_the_subscriber(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        // The confirmation email is built from the persisted subscriber (token + template).
        Newsletter::create(['site_id' => $site->id, 'email' => 'sub@example.test']);

        app()->call([new SendNewsletterWelcomeEmail($site->id, 'sub@example.test'), 'handle']);

        Mail::assertSent(
            NewsletterSubscribedMail::class,
            fn (NewsletterSubscribedMail $mail): bool => $mail->hasTo('sub@example.test') && $mail->siteName === $site->name,
        );
    }

    public function test_newsletter_requires_a_valid_email(): void
    {
        Bus::fake();
        [$site, $key] = $this->siteWithKey();

        $this->postJson($this->publicBase($site) . '/newsletter', ['email' => 'not-an-email'], $this->siteHeaders($key))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        Bus::assertNotDispatched(ProcessNewsletterSubscription::class);
    }

    public function test_subscribing_an_already_subscribed_email_returns_422(): void
    {
        Bus::fake();
        [$site, $key] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'dup@example.test']);

        $this->postJson($this->publicBase($site) . '/newsletter', ['email' => 'dup@example.test'], $this->siteHeaders($key))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'You are already subscribed.');

        Bus::assertNotDispatched(ProcessNewsletterSubscription::class);
    }

    public function test_same_email_can_subscribe_on_a_different_site(): void
    {
        Bus::fake();
        [$siteA] = $this->siteWithKey();
        [$siteB, $keyB] = $this->siteWithKey();
        Newsletter::create(['site_id' => $siteA->id, 'email' => 'dup@example.test']);

        // Already on site A, but site B has never seen this email → allowed.
        $this->postJson($this->publicBase($siteB) . '/newsletter', ['email' => 'dup@example.test'], $this->siteHeaders($keyB))
            ->assertAccepted();
    }

    public function test_unsubscribed_email_can_subscribe_again_via_http(): void
    {
        Bus::fake();
        [$site, $key] = $this->siteWithKey();
        $newsletter = Newsletter::create(['site_id' => $site->id, 'email' => 'back@example.test']);
        $newsletter->delete(); // soft-deleted (unsubscribed)

        // Soft-deleted rows are excluded from the uniqueness check → re-subscribe allowed.
        $this->postJson($this->publicBase($site) . '/newsletter', ['email' => 'back@example.test'], $this->siteHeaders($key))
            ->assertAccepted();

        Bus::assertDispatched(ProcessNewsletterSubscription::class);
    }
}
