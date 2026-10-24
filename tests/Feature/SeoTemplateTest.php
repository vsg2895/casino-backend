<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\SeoTemplate;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class SeoTemplateTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function url(Site $site, string $suffix = ''): string
    {
        return "/api/v1/admin/sites/{$site->id}/seo-templates{$suffix}";
    }

    public function test_update_returns_422_when_templates_are_missing(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson($this->url($site), [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The templates field is required.',
                'errors' => ['templates' => ['The templates field is required.']],
            ]);
    }

    public function test_update_returns_422_for_an_unknown_entity_and_saves_nothing(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson($this->url($site), ['templates' => [['entity' => 'unicorn', 'title_pattern' => 'x']]])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The selected templates.0.entity is invalid.',
                'errors' => ['templates.0.entity' => ['The selected templates.0.entity is invalid.']],
            ]);

        $this->assertDatabaseCount('seo_templates', 0);
    }

    public function test_update_trims_and_saves_patterns_and_returns_every_entity(): void
    {
        Bus::fake([RevalidateNextJsSites::class]);
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $response = $this->putJson($this->url($site), ['templates' => [
            ['entity' => SeoTemplate::ENTITY_CASINO, 'title_pattern' => '  {{name}} Review  ', 'description_pattern' => ''],
        ]]);

        $response->assertOk()->assertExactJson(['data' => [
            ['entity' => 'casino', 'title_pattern' => '{{name}} Review', 'description_pattern' => null],
            ['entity' => 'special_offer', 'title_pattern' => null, 'description_pattern' => null],
            ['entity' => 'category', 'title_pattern' => null, 'description_pattern' => null],
            ['entity' => 'page', 'title_pattern' => null, 'description_pattern' => null],
            ['entity' => 'listing', 'title_pattern' => null, 'description_pattern' => null],
        ]]);
        $this->assertDatabaseHas('seo_templates', [
            'site_id' => $site->id,
            'entity' => 'casino',
            'title_pattern' => '{{name}} Review',
            'description_pattern' => null,
        ]);
        Bus::assertDispatched(RevalidateNextJsSites::class);
    }

    public function test_update_deletes_a_row_whose_patterns_are_both_cleared(): void
    {
        Bus::fake([RevalidateNextJsSites::class]);
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        SeoTemplate::create(['site_id' => $site->id, 'entity' => 'casino', 'title_pattern' => 'Old']);

        $this->putJson($this->url($site), ['templates' => [
            ['entity' => 'casino', 'title_pattern' => ' ', 'description_pattern' => null],
        ]])->assertOk();

        $this->assertDatabaseCount('seo_templates', 0);
    }

    public function test_preview_returns_422_when_the_pattern_is_missing(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson($this->url($site, '/preview'), [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The pattern field is required.',
                'errors' => ['pattern' => ['The pattern field is required.']],
            ]);
    }

    public function test_preview_substitutes_the_example_name_when_the_name_is_blank(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['name' => 'Spin Hub']);

        $this->postJson($this->url($site, '/preview'), ['pattern' => '{{name}} on {{site_name}} {{rating}}', 'name' => null])
            ->assertOk()
            ->assertExactJson(['preview' => 'Example Casino on Spin Hub {{rating}}']);
    }

    public function test_preview_substitutes_the_example_name_when_the_name_is_omitted(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['name' => 'Spin Hub']);

        $this->postJson($this->url($site, '/preview'), ['pattern' => '{{name}} on {{site_name}}'])
            ->assertOk()
            ->assertExactJson(['preview' => 'Example Casino on Spin Hub']);
    }

    public function test_preview_substitutes_the_given_name(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['name' => 'Spin Hub']);

        $this->postJson($this->url($site, '/preview'), ['pattern' => '{{name}} on {{site_name}}', 'name' => 'Lucky Star'])
            ->assertOk()
            ->assertExactJson(['preview' => 'Lucky Star on Spin Hub']);
    }
}
