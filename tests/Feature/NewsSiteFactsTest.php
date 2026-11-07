<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Site;
use App\Services\News\NewsSiteFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NewsSiteFacts: which of THIS site's casinos a story bears on, and the stored
 * facts a rewrite may state about them.
 */
class NewsSiteFactsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['slug' => 'winpalack', 'operator_profile_enabled' => true]);
    }

    /** @param array<string, mixed> $detail */
    private function casino(string $name, array $detail = [], ?Site $site = null, bool $attached = true): Casino
    {
        $casino = Casino::factory()->create(['name' => $name, 'slug' => strtolower($name), 'active' => true]);

        if ($attached) {
            $casino->sites()->attach(($site ?? $this->site)->id, ['active' => true, 'position' => 1, 'affiliate_url' => 'https://example.com/go']);
        }

        if ($detail !== []) {
            $casino->detail()->create($detail);
        }

        return $casino;
    }

    private function match(string $story): array
    {
        return app(NewsSiteFacts::class)->matching($this->site->refresh(), $story);
    }

    public function test_a_named_regulator_finds_the_casinos_it_licenses_with_their_facts(): void
    {
        $this->casino('Northstar', [
            'licences'        => ['MGA', 'Curaçao'],
            'withdrawal_time' => '24 hours',
            'payment_methods' => ['Visa', 'Skrill'],
        ]);
        $this->casino('Riverbend', ['licences' => ['Kahnawake']]);

        $matches = $this->match('The Malta Gaming Authority fined two operators this week.');

        $this->assertCount(1, $matches);
        $this->assertSame('/casinos/northstar', $matches[0]['path']);
        $this->assertContains('It holds a licence from Malta Gaming Authority, the regulator in the story.', $matches[0]['facts']);
        $this->assertContains('Licences: MGA, Curaçao.', $matches[0]['facts']);
        $this->assertContains('Withdrawal time: 24 hours.', $matches[0]['facts']);
        $this->assertContains('Payment methods include Visa, Skrill.', $matches[0]['facts']);
    }

    public function test_accents_are_folded_and_only_whole_words_match(): void
    {
        $this->casino('Northstar', ['licences' => ['Curacao eGaming 8048/JAZ']]);
        $this->casino('Omegabet', ['licences' => ['Kahnawake']]);

        $this->assertSame(['Northstar'], array_column($this->match('New rules for Curaçao licensees.'), 'name'));
        // "Omega" contains "mga" but is not the regulator.
        $this->assertSame([], $this->match('Omega Systems signs a supplier deal.'));
    }

    public function test_a_named_casino_ranks_above_a_shared_regulator(): void
    {
        $this->casino('Northstar', ['licences' => ['MGA']]);
        $this->casino('Riverbend', ['licences' => ['MGA']]);

        $names = array_column($this->match('Riverbend receives a warning from the MGA.'), 'name');

        $this->assertSame(['Riverbend', 'Northstar'], $names);
    }

    public function test_profile_data_is_not_used_when_the_site_does_not_publish_it(): void
    {
        $this->site->update(['operator_profile_enabled' => false]);
        $this->casino('Northstar', ['licences' => ['MGA'], 'withdrawal_time' => '24 hours']);
        $this->casino('Riverbend', ['licences' => ['MGA']]);

        $matches = $this->match('Riverbend receives a warning from the MGA.');

        $this->assertSame(['Riverbend'], array_column($matches, 'name'), 'only the casino named in the story');
        $this->assertSame(['It is named in the story.'], $matches[0]['facts']);
    }

    public function test_placeholder_values_and_other_sites_casinos_are_ignored(): void
    {
        $this->casino('Northstar', ['licences' => ['MGA', 'SAMPLE LICENCE — not verified'], 'withdrawal_time' => 'Sample: 1-3 days']);
        $this->casino('Elsewhere', ['licences' => ['MGA']], Site::factory()->create());
        $this->casino('Detached', ['licences' => ['MGA']], attached: false);

        $matches = $this->match('An MGA consultation opens.');

        $this->assertSame(['Northstar'], array_column($matches, 'name'));
        $this->assertContains('Licences: MGA.', $matches[0]['facts']);
        $this->assertSame([], array_filter($matches[0]['facts'], fn (string $f) => stripos($f, 'sample') !== false));
    }

    public function test_at_most_four_casinos_are_returned(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
            $this->casino($name, ['licences' => ['MGA']]);
        }

        $this->assertCount(NewsSiteFacts::MAX_CASINOS, $this->match('The MGA publishes its annual report.'));
    }
}
