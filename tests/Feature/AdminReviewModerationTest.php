<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\InvalidateCasinoCache;
use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class AdminReviewModerationTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function review(Site $site, string $status): CasinoReview
    {
        return CasinoReview::create([
            'site_id' => $site->id,
            'casino_id' => Casino::factory()->create()->id,
            'author_name' => 'Pat',
            'author_email' => 'pat@example.test',
            'rating' => 4,
            'title' => 'Fine',
            'body' => 'Paid out within a day.',
            'status' => $status,
        ]);
    }

    public function test_visibility_returns_422_when_published_is_missing(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $review = $this->review($site, CasinoReview::STATUS_PENDING);

        $this->patchJson("/api/v1/admin/reviews/{$review->id}/visibility", [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The published field is required.',
                'errors' => ['published' => ['The published field is required.']],
            ]);

        $this->assertSame(CasinoReview::STATUS_PENDING, $review->fresh()->status);
    }

    public function test_visibility_returns_404_for_an_unknown_review_before_validating(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/reviews/999/visibility', [])->assertNotFound();
    }

    public function test_bulk_returns_422_for_an_unknown_action_and_changes_nothing(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $review = $this->review($site, CasinoReview::STATUS_PENDING);

        $this->postJson('/api/v1/admin/reviews/bulk', ['action' => 'archive', 'ids' => [$review->id]])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The selected action is invalid.',
                'errors' => ['action' => ['The selected action is invalid.']],
            ]);

        $this->assertSame(CasinoReview::STATUS_PENDING, $review->fresh()->status);
    }

    public function test_bulk_returns_422_listing_every_missing_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/reviews/bulk', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The action field is required. (and 1 more error)',
                'errors' => [
                    'action' => ['The action field is required.'],
                    'ids' => ['The ids field is required.'],
                ],
            ]);
    }

    public function test_bulk_publish_publishes_and_invalidates_each_site_once(): void
    {
        Bus::fake([InvalidateCasinoCache::class]);
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $first = $this->review($site, CasinoReview::STATUS_PENDING);
        $second = $this->review($site, CasinoReview::STATUS_PENDING);

        $this->postJson('/api/v1/admin/reviews/bulk', ['action' => 'publish', 'ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertExactJson(['affected' => 2]);

        $this->assertSame(CasinoReview::STATUS_PUBLISHED, $first->fresh()->status);
        $this->assertNotNull($second->fresh()->published_at);
        Bus::assertDispatchedTimes(InvalidateCasinoCache::class, 1);
    }

    public function test_bulk_hide_of_never_visible_reviews_invalidates_nothing(): void
    {
        Bus::fake([InvalidateCasinoCache::class]);
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $review = $this->review($site, CasinoReview::STATUS_PENDING);

        $this->postJson('/api/v1/admin/reviews/bulk', ['action' => 'hide', 'ids' => [$review->id]])
            ->assertOk()
            ->assertExactJson(['affected' => 1]);

        $this->assertSame(CasinoReview::STATUS_HIDDEN, $review->fresh()->status);
        Bus::assertNotDispatched(InvalidateCasinoCache::class);
    }
}
