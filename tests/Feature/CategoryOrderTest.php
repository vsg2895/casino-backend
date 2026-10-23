<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The order of the casino-category chips, set once and obeyed everywhere.
 *
 * THE CONTRACT: the position an operator sets in the admin is the position the
 * chip occupies on EVERY public site. Categories are global master data with no
 * per-site pivot, so there is one order and every domain renders it.
 *
 * WHY THE WHOLE LIST IS RENUMBERED rather than one row's number being patched.
 * The values this platform shipped with were not contiguous and not unique —
 * two categories both sat at 0, then 1, 2, 3 — so setting one category's
 * `sort_order` to 2 produced a TIE, and `scopeOrdered()` breaks a tie on name.
 * An operator asking for "second" got third or fourth depending on spelling.
 * Writing the index of the submitted order makes the number mean exactly what
 * it says, and these tests are what stop that regressing.
 */
class CategoryOrderTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->site, $this->key] = $this->siteWithKey();
    }

    /** A category with one visible casino, so it survives the public filter. */
    private function category(string $name, int $sortOrder): Category
    {
        $category = Category::create(['name' => $name, 'sort_order' => $sortOrder]);

        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($this->site->id, [
            'active'        => true,
            'position'      => 1,
            'affiliate_url' => 'https://example.test/go',
        ]);
        $casino->categories()->attach($category->id);

        return $category;
    }

    /** @return list<string> the category names, in the order the site renders them */
    private function publicOrder(): array
    {
        $response = $this->withHeader('X-Site-Key', $this->key)
            ->getJson("/api/v1/public/sites/{$this->site->slug}/categories")
            ->assertOk();

        return array_column($response->json('data'), 'name');
    }

    private function reorder(array $ids)
    {
        return $this->postJson('/api/v1/admin/categories/reorder', ['ids' => $ids]);
    }

    public function test_the_admin_order_is_the_public_order(): void
    {
        $this->actingAsAdmin();
        $popular = $this->category('Most Popular', 0);
        $crypto  = $this->category('Crypto Oriented', 5);
        $betting = $this->category('Betting', 4);

        $this->reorder([$popular->id, $crypto->id, $betting->id])->assertOk();

        $this->assertSame(['Most Popular', 'Crypto Oriented', 'Betting'], $this->publicOrder());
    }

    /**
     * The exact request that prompted this: "Crypto Oriented — 2, and on every
     * site it is the second chip."
     */
    public function test_a_category_moved_to_second_is_second(): void
    {
        $this->actingAsAdmin();
        $popular = $this->category('Most Popular', 0);
        $online  = $this->category('Online Casinos', 0);   // the shipped TIE
        $bonuses = $this->category('Best Bonuses', 1);
        $crypto  = $this->category('Crypto Oriented', 5);

        $this->reorder([$popular->id, $crypto->id, $online->id, $bonuses->id])->assertOk();

        $order = $this->publicOrder();

        $this->assertSame('Crypto Oriented', $order[1], 'position 2 must be the second chip');
        $this->assertSame(
            ['Most Popular', 'Crypto Oriented', 'Online Casinos', 'Best Bonuses'],
            $order,
        );
    }

    /**
     * The stored values must come out contiguous — that is what makes a tie
     * impossible, and a tie is what broke the old meaning of the number.
     */
    public function test_reordering_renumbers_contiguously_from_zero(): void
    {
        $this->actingAsAdmin();
        // Deliberately degenerate input: duplicates and a gap.
        $a = $this->category('Alpha', 0);
        $b = $this->category('Bravo', 0);
        $c = $this->category('Charlie', 99);

        $this->reorder([$c->id, $a->id, $b->id])->assertOk();

        $this->assertSame(0, $c->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    /** One order, every domain — there is no per-site override to diverge. */
    public function test_every_site_renders_the_same_order(): void
    {
        $this->actingAsAdmin();
        [$second, $secondKey] = $this->siteWithKey();

        $a = $this->category('Alpha', 0);
        $b = $this->category('Bravo', 1);

        // The second site needs its own visible casino in each category.
        foreach ([$a, $b] as $category) {
            $casino = Casino::factory()->create(['active' => true]);
            $casino->sites()->attach($second->id, [
                'active'        => true,
                'position'      => 1,
                'affiliate_url' => 'https://example.test/go',
            ]);
            $casino->categories()->attach($category->id);
        }

        $this->reorder([$b->id, $a->id])->assertOk();

        $other = $this->withHeader('X-Site-Key', $secondKey)
            ->getJson("/api/v1/public/sites/{$second->slug}/categories")
            ->assertOk();

        $this->assertSame(['Bravo', 'Alpha'], $this->publicOrder());
        $this->assertSame(['Bravo', 'Alpha'], array_column($other->json('data'), 'name'));
    }

    /** The response carries the re-ordered list, so the admin need not refetch. */
    public function test_the_response_returns_the_new_order(): void
    {
        $this->actingAsAdmin();
        $a = $this->category('Alpha', 0);
        $b = $this->category('Bravo', 1);

        $response = $this->reorder([$b->id, $a->id])->assertOk();

        $this->assertSame(2, $response->json('reordered'));
        $this->assertSame(['Bravo', 'Alpha'], array_column($response->json('data'), 'name'));
    }

    public function test_it_requires_an_authenticated_admin(): void
    {
        $category = $this->category('Alpha', 0);

        $this->postJson('/api/v1/admin/categories/reorder', ['ids' => [$category->id]])
            ->assertUnauthorized();
    }

    public function test_an_unknown_id_is_rejected_and_nothing_moves(): void
    {
        $this->actingAsAdmin();
        $a = $this->category('Alpha', 0);
        $b = $this->category('Bravo', 1);

        $this->reorder([$b->id, 999999])->assertStatus(422)->assertJsonValidationErrors('ids.1');

        // The transaction never ran, so the original order stands.
        $this->assertSame(0, $a->fresh()->sort_order);
        $this->assertSame(1, $b->fresh()->sort_order);
    }

    public function test_a_duplicated_id_is_rejected(): void
    {
        $this->actingAsAdmin();
        $a = $this->category('Alpha', 0);

        // Two slots claiming one category has no single correct answer.
        $this->reorder([$a->id, $a->id])->assertStatus(422);
    }

    /**
     * The literal segment must not be swallowed by `{category}` model binding —
     * the ordering rule this project's routes file states explicitly.
     */
    public function test_the_literal_route_is_not_shadowed_by_model_binding(): void
    {
        $this->actingAsAdmin();
        $a = $this->category('Alpha', 0);

        $this->reorder([$a->id])->assertOk();
    }
}
