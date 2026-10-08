<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use Database\Seeders\CryptoOrientedCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The seeder is going to be run against production, where the two failure modes
 * are the expensive ones: a duplicate category, or a logo that silently
 * replaces one an editor uploaded. Both are tested here rather than discovered
 * there.
 *
 * `Storage::fake('public')` is what makes this safe to run at all — the real
 * seeder writes into `storage/app/public`, and a test that did that would leave
 * a file behind on the developer's machine.
 */
class CryptoOrientedCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    private const string LOGO_PATH = 'uploads/category-logos/crypto-oriented.svg';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Named `runSeeder`, not `seed`: TestCase already has a public `seed()`, and
     * a private override of it is a fatal error before a single test runs.
     */
    private function runSeeder(): void
    {
        $this->seed(CryptoOrientedCategorySeeder::class);
    }

    public function test_it_creates_the_category_and_writes_the_logo(): void
    {
        $this->runSeeder();

        $category = Category::query()->where('slug', 'crypto-oriented')->first();

        $this->assertNotNull($category);
        $this->assertSame('Crypto Oriented', $category->name);
        $this->assertSame(self::LOGO_PATH, $category->logo_path);
        $this->assertSame(5, $category->sort_order);

        Storage::disk('public')->assertExists(self::LOGO_PATH);

        // The bytes went through the sanitiser, so what landed is still SVG and
        // still carries the artwork rather than an empty shell.
        $svg = Storage::disk('public')->get(self::LOGO_PATH);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('linearGradient', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    public function test_running_it_twice_creates_one_category(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(1, Category::query()->where('slug', 'crypto-oriented')->count());
    }

    public function test_it_never_overwrites_a_logo_that_is_already_there(): void
    {
        // Stands in for a logo an editor uploaded through the admin after the
        // first run: same path, different bytes.
        Storage::disk('public')->put(self::LOGO_PATH, '<svg xmlns="http://www.w3.org/2000/svg">editor</svg>');

        $this->runSeeder();

        $this->assertStringContainsString('editor', Storage::disk('public')->get(self::LOGO_PATH));
    }

    public function test_it_leaves_an_existing_category_renamed_and_reordered_as_it_is(): void
    {
        Category::create([
            'name'       => 'Crypto',
            'slug'       => 'crypto-oriented',
            'logo_path'  => 'uploads/category-logos/something-else.svg',
            'sort_order' => 1,
        ]);

        $this->runSeeder();

        $category = Category::query()->where('slug', 'crypto-oriented')->firstOrFail();

        // Renaming it, moving it and swapping its logo are editorial decisions.
        $this->assertSame('Crypto', $category->name);
        $this->assertSame(1, $category->sort_order);
        $this->assertSame('uploads/category-logos/something-else.svg', $category->logo_path);
    }

    public function test_it_fills_an_empty_logo_path_on_an_existing_category(): void
    {
        Category::create([
            'name'       => 'Crypto Oriented',
            'slug'       => 'crypto-oriented',
            'logo_path'  => null,
            'sort_order' => 5,
        ]);

        $this->runSeeder();

        $this->assertSame(
            self::LOGO_PATH,
            Category::query()->where('slug', 'crypto-oriented')->value('logo_path'),
        );
    }
}
