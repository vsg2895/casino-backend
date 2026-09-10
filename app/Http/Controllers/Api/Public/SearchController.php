<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SearchSuggestRequest;
use App\Http\Resources\SearchSuggestionResource;
use App\Models\Site;
use App\Services\Search\SearchIndexer;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Grouped search suggestions for the header overlay.
 *
 * Thin by design: validation is the FormRequest's job, every query belongs to
 * {@see SearchService}, and this class only resolves the site, caches, and
 * shapes the response.
 *
 * The site comes from `app('current_site')`, bound by VerifySiteAccess after it
 * has hashed-checked the X-Site-Key header — never from the request body. That
 * is the whole tenancy guarantee for this endpoint.
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function suggest(SearchSuggestRequest $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $query = (string) $request->validated('q');
        $section = $request->validated('section');
        $page = (int) ($request->validated('page') ?? 1);

        $payload = $this->remember($site, $query, $section, $page, fn (): array =>
            $this->search->suggest($site, $query, $section, $page));

        // Resources are applied AFTER the cache so what is stored stays plain
        // arrays — cached Resource instances are the same __PHP_Incomplete_Class
        // trap that silently truncated the casino endpoint (see docs/CACHING.md).
        $payload['sections'] = array_map(static fn (array $group): array => [
            ...$group,
            'items' => SearchSuggestionResource::collection($group['items'])->resolve(),
        ], $payload['sections']);

        return response()->json($payload);
    }

    /**
     * Cache one response for a short window.
     *
     * The endpoint is hit on EVERY keystroke, so the win is collapsing a burst
     * of identical requests — several visitors typing the same brand, or one
     * visitor backspacing over a term they already typed — not long-lived
     * caching. Hence ~60s.
     *
     * Degrades gracefully: if the cache store is unreachable (Redis down), the
     * search still answers from MySQL rather than failing. A search box that
     * stops working because a cache is down would be a worse outage than the
     * cache miss it is avoiding.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function remember(Site $site, string $query, ?string $section, int $page, callable $callback): array
    {
        $key = sprintf(
            'search:suggest:%d:v%d:%s:%s:%d',
            $site->id,
            // Bumped by the indexer on every write, so an admin hiding a casino
            // retires this site's cached responses at once instead of leaving
            // them to expire.
            SearchIndexer::version((int) $site->id),
            // Hashed, not raw: arbitrary visitor input must not become part of a
            // cache key, where length limits and delimiters are its problem.
            hash('xxh128', mb_strtolower($query)),
            $section ?? 'all',
            $page,
        );

        try {
            return Cache::remember($key, (int) config('search.cache_ttl', 60), $callback);
        } catch (Throwable) {
            return $callback();
        }
    }
}
