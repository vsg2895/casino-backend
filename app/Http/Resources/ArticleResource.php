<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Article
 *
 * `body` is omitted from LISTINGS via `whenLoaded`-style gating in the
 * controller rather than here, because a listing of twenty articles would
 * otherwise ship twenty full article bodies to render twenty cards.
 */
class ArticleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'site_id' => $this->site_id,
            'type'  => $this->type,
            // Provenance. Null on anything written by hand, which is how both
            // the admin and the public page tell the two apart. `source_ref`
            // is internal and deliberately NOT exposed — it is a dedup key,
            // not information a reader or an editor needs.
            'source_name' => $this->source_name,
            'source_url'  => $this->source_url,
            // Null when the body is empty — the card shows nothing rather than
            // claiming "0 min read".
            'read_minutes' => $this->read_minutes,
            'news_category_id' => $this->news_category_id,
            // Only when eager-loaded — a listing that forgot to load it gets
            // null rather than an N+1 query per card.
            'news_category' => $this->whenLoaded('newsCategory', fn () => [
                'id'   => $this->newsCategory->id,
                'name' => $this->newsCategory->name,
                'slug' => $this->newsCategory->slug,
            ]),
            'title' => $this->title,
            'slug'    => $this->slug,
            'excerpt' => $this->excerpt,
            // Only present when the controller selected it — see the class note.
            'body'    => $this->when(! is_null($this->body) || $this->isLoadedForDetail(), $this->body),
            'hero_image_path' => $this->hero_image_path,
            // ISO-8601 STRING, not Carbon: these responses go through SiteCache,
            // and a serialized Carbon comes back as __PHP_Incomplete_Class.
            'published_at'    => $this->published_at?->toISOString(),
            'position'        => (int) $this->position,
            'active'   => (bool) $this->active,
            'featured' => (bool) $this->featured,
            'meta_title'      => $this->meta_title,
            'meta_description' => $this->meta_description,
            'canonical_url'   => $this->canonical_url,
            'noindex'         => (bool) $this->noindex,
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }

    /** The detail query selects every column; a listing query does not. */
    private function isLoadedForDetail(): bool
    {
        return array_key_exists('body', $this->resource->getAttributes());
    }
}
