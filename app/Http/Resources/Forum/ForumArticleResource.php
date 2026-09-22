<?php

declare(strict_types=1);

namespace App\Http\Resources\Forum;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ForumArticle */
class ForumArticleResource extends JsonResource
{
    /**
     * Whether to include the article body.
     *
     * An explicit flag rather than a route check: a listing of 20 articles has
     * no use for 20 MEDIUMTEXT bodies, and whether the caller wants one is the
     * caller's decision, not something to infer from the current URL.
     */
    private bool $withBody = false;

    public function withBody(): self
    {
        $this->withBody = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => (int) $this->id,
            'title'       => $this->title,
            'slug'        => $this->slug,
            'excerpt'     => $this->excerpt,
            'body'        => $this->when($this->withBody, fn () => $this->body),
            'cover_image_path' => $this->cover_image_path,
            'pinned'      => (bool) $this->pinned,
            'locked'      => (bool) $this->locked,
            'posts_count' => (int) $this->posts_count,
            'views_count' => (int) $this->views_count,
            'published_at' => $this->published_at?->toISOString(),
            'last_post_at' => $this->last_post_at?->toISOString(),
            'category'    => $this->whenLoaded('category', fn (): array => [
                'id'   => (int) $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'author'      => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'name' => $this->author->name,
            ]),
        ];
    }
}
