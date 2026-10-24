<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class CasinoFacetsTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // facets are cached per site; isolate each test
    }

    private function attachedCasino(Site $site, Category $category, array $attrs = [], bool $pivotActive = true): Casino
    {
        $casino = Casino::factory()->create($attrs);
        $casino->categories()->attach($category->id);
        $casino->sites()->attach($site->id, [
            'affiliate_url' => 'https://example.test/go/'.$casino->slug,
            'position' => 0,
            'featured' => false,
            'active' => $pivotActive,
        ]);

        return $casino;
    }

    public function test_counts_only_casinos_the_site_publishes(): void
    {
        [$site, $key] = $this->siteWithKey();
        $slots = Category::factory()->create(['name' => 'Slots', 'slug' => 'slots']);
        $this->attachedCasino($site, $slots);
        $this->attachedCasino($site, $slots, ['active' => false]);
        $this->attachedCasino($site, $slots, pivotActive: false);
        $this->attachedCasino($site, $slots)->delete();

        $response = $this->getJson($this->publicBase($site).'/casinos/facets', $this->siteHeaders($key));

        $response->assertOk()->assertExactJson(['data' => [[
            'facet' => 'category',
            'label' => 'Category',
            'values' => [['value' => 'slots', 'label' => 'Slots', 'count' => 1]],
        ]]]);
    }

    public function test_returns_no_facets_when_the_site_publishes_no_casinos(): void
    {
        [$site, $key] = $this->siteWithKey();
        $slots = Category::factory()->create(['name' => 'Slots', 'slug' => 'slots']);
        $this->attachedCasino($site, $slots)->delete();

        $response = $this->getJson($this->publicBase($site).'/casinos/facets', $this->siteHeaders($key));

        $response->assertOk()->assertExactJson(['data' => []]);
    }
}
