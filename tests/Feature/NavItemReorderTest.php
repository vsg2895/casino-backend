<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NavItem;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class NavItemReorderTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function item(Site $site, string $label, int $position): NavItem
    {
        return NavItem::create([
            'site_id' => $site->id,
            'location' => NavItem::LOCATION_HEADER,
            'label' => $label,
            'url' => '/'.strtolower($label),
            'position' => $position,
            'active' => true,
        ]);
    }

    private function reorderUrl(Site $site): string
    {
        return "/api/v1/admin/sites/{$site->id}/nav-items/reorder";
    }

    public function test_returns_401_without_an_admin_token(): void
    {
        [$site] = $this->siteWithKey();

        $this->postJson($this->reorderUrl($site), ['ids' => [1]])->assertUnauthorized();
    }

    public function test_returns_422_when_ids_are_missing(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson($this->reorderUrl($site), [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The ids field is required.',
                'errors' => ['ids' => ['The ids field is required.']],
            ]);
    }

    public function test_returns_422_when_an_id_is_not_an_integer(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson($this->reorderUrl($site), ['ids' => ['abc']])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The ids.0 field must be an integer.',
                'errors' => ['ids.0' => ['The ids.0 field must be an integer.']],
            ]);
    }

    public function test_renumbers_this_sites_items_in_the_given_order(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $home = $this->item($site, 'Home', 0);
        $casinos = $this->item($site, 'Casinos', 1);
        $bonus = $this->item($site, 'Bonus', 2);

        $this->postJson($this->reorderUrl($site), ['ids' => [$bonus->id, $home->id, $casinos->id]])
            ->assertOk()
            ->assertExactJson(['reordered' => 3]);

        $this->assertSame(0, $bonus->fresh()->position);
        $this->assertSame(1, $home->fresh()->position);
        $this->assertSame(2, $casinos->fresh()->position);
    }

    public function test_ignores_ids_that_belong_to_another_sites_menu(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['slug' => 'site-a', 'domain' => 'a.example.test']);
        [$other] = $this->siteWithKey(['slug' => 'site-b', 'domain' => 'b.example.test']);
        $own = $this->item($site, 'Home', 5);
        $foreign = $this->item($other, 'Elsewhere', 7);

        $this->postJson($this->reorderUrl($site), ['ids' => [$foreign->id, $own->id]])
            ->assertOk()
            ->assertExactJson(['reordered' => 1]);

        $this->assertSame(1, $own->fresh()->position);
        $this->assertSame(7, $foreign->fresh()->position);
    }
}
