<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Article;
use App\Support\Byline;
use App\Support\Media\ImageDimensions;
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
            'title' => $this->title,
            'slug'    => $this->slug,
            'excerpt' => $this->excerpt,
            // Only present when the controller selected it — see the class note.
            'body'    => $this->when(! is_null($this->body) || $this->isLoadedForDetail(), $this->body),
            // Detail responses only, like the body. Plain text.
            'editor_note' => $this->when($this->isLoadedForDetail(), fn (): ?string => $this->editor_note),
            'hero_image_path' => $this->hero_image_path,
            // Detail responses only: whether the cover is big enough to be the
            // share image. Null for an SVG, a remote URL or a missing file —
            // see ImageDimensions.
            'hero_image_size' => $this->when($this->isLoadedForDetail(), fn (): ?array => ImageDimensions::forPublicPath($this->hero_image_path)),
            // ISO-8601 STRING, not Carbon: these responses go through SiteCache,
            // and a serialized Carbon comes back as __PHP_Incomplete_Class.
            'published_at'    => $this->published_at?->toISOString(),
            'active'   => (bool) $this->active,
            'featured' => (bool) $this->featured,
            'to_be_most_popular' => (bool) $this->to_be_most_popular,
            'meta_title'      => $this->meta_title,
            'meta_description' => $this->meta_description,
            'canonical_url'   => $this->canonical_url,
            'noindex'         => (bool) $this->noindex,
            // Admin listing only — see ArticleController::index(). True while a
            // scraped draft still holds the source's headline and teaser.
            'awaiting_rewrite' => $this->when(
                array_key_exists('awaiting_rewrite', $this->resource->getAttributes()),
                fn () => (bool) $this->resource->getAttributes()['awaiting_rewrite'],
            ),
            /*
             * Admin listing only (see ArticleController::rewriteColumns()): where
             * a scraped post stands with `news:rewrite`. `state` is one of
             * rewritten | not_attempted | attempt_failed | written_here; the rest
             * is the last attempt — absent before the 2026_12_04 migration.
             * `last_error` is why the last attempt produced no copy (the
             * rejection or the failure); null once one succeeds.
             */
            'rewrite' => $this->when(
                array_key_exists('rewrite_state', $this->resource->getAttributes()),
                fn (): array => [
                    'state'           => (string) $this->resource->getAttributes()['rewrite_state'],
                    'attempts'        => (int) ($this->resource->getAttributes()['rewrite_attempts'] ?? 0),
                    // Guarded: the column is absent before the migration, and the
                    // listing only selects what exists.
                    'last_attempt_at' => array_key_exists('rewrite_attempted_at', $this->resource->getAttributes())
                        ? $this->rewrite_attempted_at?->toISOString()
                        : null,
                    'last_result'     => $this->resource->getAttributes()['rewrite_result'] ?? null,
                    'last_error'      => $this->resource->getAttributes()['rewrite_error'] ?? null,
                ],
            ),
            // Byline ids for the admin form; the resolved byline only when the
            // detail query loaded the (visible) authors.
            'author_id'        => $this->author_id,
            'reviewed_by_id'   => $this->reviewed_by_id,
            'last_reviewed_at' => $this->last_reviewed_at?->toISOString(),
            'byline'           => $this->when(
                $this->relationLoaded('author') || $this->relationLoaded('reviewer'),
                fn () => Byline::from(
                    $this->relationLoaded('author') ? $this->author : null,
                    $this->relationLoaded('reviewer') ? $this->reviewer : null,
                    $this->last_reviewed_at,
                ),
            ),
            'updated_at'      => $this->updated_at?->toISOString(),
            // Detail responses only: the date a reader's "updated" means — the
            // text's last change, never an admin toggle. See contentModifiedAt().
            'content_modified_at' => $this->when($this->isLoadedForDetail(), fn (): ?string => $this->contentModifiedAt()?->toISOString()),
        ];
    }

    /** The detail query selects every column; a listing query does not. */
    private function isLoadedForDetail(): bool
    {
        return array_key_exists('body', $this->resource->getAttributes());
    }
}
