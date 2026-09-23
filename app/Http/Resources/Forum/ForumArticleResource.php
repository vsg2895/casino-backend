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

    /**
     * Whether to name the admin who actually wrote it.
     *
     * OFF by default, so every public surface gets the team label without
     * having to remember to ask for it — including any added later. The admin
     * panel opts in, because an editor looking at the list needs to know who
     * wrote which thread.
     */
    private bool $withRealAuthor = false;

    public function withBody(): self
    {
        $this->withBody = true;

        return $this;
    }

    /** Admin panel only — see {@see self::$withRealAuthor}. */
    public function withRealAuthor(): self
    {
        $this->withRealAuthor = true;

        return $this;
    }

    /**
     * What the public sees instead of the admin's personal name.
     *
     * ── Why no role check ───────────────────────────────────────────────────
     *
     * `forum_articles.user_id` is a foreign key to `users`, the ADMIN table.
     * Members live in `forum_users` and reach the forum only through
     * `forum_posts.forum_user_id`. So an article author is staff by
     * construction — there is no row a member could ever occupy here, and a
     * role lookup would be asking a question the schema has already answered.
     *
     * It also fails in the safe direction. A role check would publish an
     * admin's personal name the moment somebody created an account without
     * assigning a role, which is precisely the leak this exists to prevent.
     *
     * Member-authored replies are untouched: they render through
     * ForumPostResource from `forum_users.display_name`, and nothing here can
     * reach them.
     *
     * ── Why the site's own name ─────────────────────────────────────────────
     *
     * Derived, not hardcoded: the same admin writes for six domains, and
     * "Winpalack Team" on another brand's forum would be wrong. The public
     * request already has its site bound by VerifySiteAccess, so this costs no
     * query; the relation is the fallback for anything resolved outside a
     * site-scoped request.
     */
    private function teamName(): string
    {
        return \App\Support\Forum\ForumTeamName::for($this->resource->site);
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
                'name' => $this->withRealAuthor ? $this->author->name : $this->teamName(),
            ]),
        ];
    }
}
