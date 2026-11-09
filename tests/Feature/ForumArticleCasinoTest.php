<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumSection;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * A forum topic may name the casino it is about — one shown on its site — and
 * the admin's Discussions list filters by it. Admin only: the public forum
 * resource does not carry it.
 */
class ForumArticleCasinoTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private ForumCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        [$this->site] = $this->siteWithKey(['forum_enabled' => true]);
        $section = ForumSection::create(['site_id' => $this->site->id, 'name' => 'General']);
        $this->category = ForumCategory::create([
            'site_id' => $this->site->id, 'forum_section_id' => $section->id, 'name' => 'Withdrawals',
        ]);
    }

    private function attachedCasino(string $name): Casino
    {
        $casino = Casino::factory()->create(['name' => $name]);
        $casino->sites()->attach($this->site->id, ['active' => true, 'affiliate_url' => 'https://example.com/go']);

        return $casino;
    }

    /** @param  array<string, mixed>  $attrs */
    private function topic(string $title, array $attrs = []): int
    {
        return (int) $this->postJson("/api/v1/admin/sites/{$this->site->id}/forum-articles", [
            'forum_category_id' => $this->category->id,
            'title'             => $title,
            'body'              => '<p>Body.</p>',
            'status'            => 'published',
            ...$attrs,
        ])->assertCreated()->json('data.id');
    }

    public function test_a_topic_saves_its_casino_and_the_list_filters_by_it(): void
    {
        $alpha = $this->attachedCasino('Alpha Casino');
        $beta = $this->attachedCasino('Beta Casino');

        $this->topic('About alpha', ['casino_id' => $alpha->id]);
        $this->topic('About beta', ['casino_id' => $beta->id]);
        $this->topic('General chat');

        $base = "/api/v1/admin/sites/{$this->site->id}/forum-articles";

        $this->getJson("{$base}?casino_id={$alpha->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'About alpha')
            ->assertJsonPath('data.0.casino.name', 'Alpha Casino');
        $this->getJson("{$base}/count?casino_id={$alpha->id}")->assertExactJson(['total' => 1]);

        $this->getJson("{$base}?casino_id=none")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'General chat')
            ->assertJsonPath('data.0.casino', null);

        $this->getJson("{$base}/count")->assertExactJson(['total' => 3]);
    }

    public function test_only_a_casino_shown_on_the_site_is_accepted(): void
    {
        $elsewhere = Casino::factory()->create();

        $this->postJson("/api/v1/admin/sites/{$this->site->id}/forum-articles", [
            'forum_category_id' => $this->category->id,
            'casino_id'         => $elsewhere->id,
            'title'             => 'Wrong casino',
            'body'              => '<p>Body.</p>',
            'status'            => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('casino_id');
    }

    public function test_the_casino_options_are_the_sites_casinos_and_an_update_can_clear_it(): void
    {
        $alpha = $this->attachedCasino('Alpha Casino');
        Casino::factory()->create(['name' => 'Not on this site']);

        $this->getJson("/api/v1/admin/sites/{$this->site->id}/forum-articles/casinos")
            ->assertOk()
            ->assertExactJson(['data' => [['id' => $alpha->id, 'name' => 'Alpha Casino']]]);

        $id = $this->topic('About alpha', ['casino_id' => $alpha->id]);

        $this->putJson("/api/v1/admin/sites/{$this->site->id}/forum-articles/{$id}", [
            'forum_category_id' => $this->category->id,
            'casino_id'         => null,
            'title'             => 'About alpha',
            'body'              => '<p>Body.</p>',
            'status'            => 'published',
        ])->assertOk()->assertJsonPath('data.casino_id', null);

        $this->assertNull(ForumArticle::query()->find($id)?->casino_id);
    }
}
