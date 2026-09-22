<?php

declare(strict_types=1);

namespace App\Http\Resources\Forum;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One board on the forum index.
 *
 * The last-post block is built from the category's own denormalised columns plus
 * ONE hydration query for the titles and display names — never from a per-row
 * lookup into forum_posts. See ForumIndexService.
 *
 * @mixin \App\Models\ForumCategory
 */
class ForumCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => (int) $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'icon'           => $this->icon,
            'articles_count' => (int) $this->articles_count,
            'posts_count'    => (int) $this->posts_count,
            // Null on a board nobody has posted in yet — the front end renders
            // its empty state from this rather than from a zero count, because
            // an archived board can have posts and no recent activity.
            // `when`, not `whenNotNull`: whenNotNull returns the VALUE it was
            // given when that value is non-null, so the closure would land in
            // its $default slot and the block would serialise as a bare
            // timestamp string. Caught by calling the endpoint, not by reading.
            'last_post'      => $this->when($this->last_post_at !== null, fn (): array => [
                'at'            => $this->last_post_at?->toISOString(),
                'article_id'    => $this->last_post_article_id ? (int) $this->last_post_article_id : null,
                'article_title' => $this->last_post_article_title ?? null,
                'article_slug'  => $this->last_post_article_slug ?? null,
                'author_name'   => $this->last_post_author_name ?? null,
                'post_id'       => $this->last_post_id ? (int) $this->last_post_id : null,
            ]),
        ];
    }
}
