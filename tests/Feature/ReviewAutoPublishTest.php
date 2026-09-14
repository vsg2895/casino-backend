<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Post- vs pre-moderation of visitor reviews (`sites.review_auto_publish`).
 *
 * The switch decides whether a submitted review is public immediately or waits
 * in a queue, and both halves matter: with it ON a rejected-looking review must
 * still be hideable afterwards, and with it OFF nothing may reach the public API
 * before an admin acts.
 */
class ReviewAutoPublishTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array{0: Site, 1: string, 2: Casino} */
    private function siteWithCasino(bool $autoPublish = true): array
    {
        [$site, $key] = $this->siteWithKey([
            'reviews_enabled'     => true,
            'review_auto_publish' => $autoPublish,
        ]);

        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test', 'active' => true]);

        return [$site, $key, $casino];
    }

    private function submit(Site $site, string $key, Casino $casino, array $overrides = [])
    {
        return $this->postJson(
            $this->publicBase($site) . '/casinos/' . $casino->slug . '/reviews',
            [
                'author_name' => 'Visitor',
                'rating'      => 5,
                'body'        => 'A perfectly ordinary review body, long enough to pass validation.',
                ...$overrides,
            ],
            $this->siteHeaders($key),
        );
    }

    public function test_the_column_defaults_to_on(): void
    {
        // Deliberately unlike reviews_enabled, which defaults OFF: this one is
        // only ever read after reviews_enabled has already passed, so it cannot
        // open anything on a site that is not already showing reviews.
        [$site] = $this->siteWithKey();

        $this->assertTrue((bool) $site->fresh()->review_auto_publish);
    }

    public function test_a_review_is_published_immediately_when_the_switch_is_on(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino(autoPublish: true);

        $response = $this->submit($site, $key, $casino)->assertStatus(201);

        // The flag the form reads to decide which confirmation to show.
        $response->assertJsonPath('published', true);

        $review = CasinoReview::firstOrFail();
        $this->assertSame(CasinoReview::STATUS_PUBLISHED, $review->status);
        // Stamped in the same write: the feed orders by published_at, and a
        // published row with a NULL timestamp sorts to the wrong end.
        $this->assertNotNull($review->published_at);
    }

    public function test_a_published_review_is_visible_on_the_public_api_at_once(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino(autoPublish: true);

        $this->submit($site, $key, $casino, ['title' => 'Zeta headline']);

        $this->getJson(
            $this->publicBase($site) . '/casinos/' . $casino->slug . '/reviews',
            $this->siteHeaders($key),
        )->assertOk()->assertJsonPath('data.reviews.0.title', 'Zeta headline');
    }

    public function test_a_review_waits_for_moderation_when_the_switch_is_off(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino(autoPublish: false);

        $this->submit($site, $key, $casino)->assertStatus(202)->assertJsonPath('published', false);

        $review = CasinoReview::firstOrFail();
        $this->assertSame(CasinoReview::STATUS_PENDING, $review->status);
        $this->assertNull($review->published_at);

        // And nothing reaches the public API.
        $this->getJson(
            $this->publicBase($site) . '/casinos/' . $casino->slug . '/reviews',
            $this->siteHeaders($key),
        )->assertOk()->assertJsonPath('data.summary.total', 0);
    }

    public function test_the_submitter_can_never_choose_their_own_status(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino(autoPublish: false);

        // Status is set by the controller and rejected by the Form Request; a
        // visitor picks their words, not their visibility.
        $this->submit($site, $key, $casino, ['status' => 'published'])->assertStatus(202);

        $this->assertSame(CasinoReview::STATUS_PENDING, CasinoReview::firstOrFail()->status);
    }

    public function test_an_admin_can_hide_an_auto_published_review(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino(autoPublish: true);
        $this->submit($site, $key, $casino);
        $review = CasinoReview::firstOrFail();

        $this->actingAsAdmin();
        $this->patchJson("/api/v1/admin/reviews/{$review->id}/visibility", ['published' => false])->assertOk();

        $this->assertSame(CasinoReview::STATUS_HIDDEN, $review->fresh()->status);

        $this->getJson(
            $this->publicBase($site) . '/casinos/' . $casino->slug . '/reviews',
            $this->siteHeaders($key),
        )->assertOk()->assertJsonPath('data.summary.total', 0);
    }

    public function test_hidden_is_distinct_from_pending(): void
    {
        // The three states exist so the moderation queue can tell "nobody has
        // looked at this" apart from "looked at and rejected". Collapsing them
        // would lose the queue.
        [$site, $key, $casino] = $this->siteWithCasino(autoPublish: true);
        $this->submit($site, $key, $casino);
        $review = CasinoReview::firstOrFail();

        $review->setPublished(false);
        $this->assertSame(CasinoReview::STATUS_HIDDEN, $review->fresh()->status);
        $this->assertNotSame(CasinoReview::STATUS_PENDING, $review->fresh()->status);

        // Re-publishing keeps the original published_at rather than restamping.
        $original = $review->fresh()->published_at;
        $review->setPublished(true);
        $this->assertEquals($original, $review->fresh()->published_at);
    }

    public function test_reviews_are_rejected_entirely_when_the_site_has_them_off(): void
    {
        [$site, $key] = $this->siteWithKey(['reviews_enabled' => false]);
        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test', 'active' => true]);

        $this->submit($site, $key, $casino)->assertNotFound();
        $this->assertSame(0, CasinoReview::count());
    }
}
