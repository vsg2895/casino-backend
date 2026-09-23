<?php

declare(strict_types=1);

namespace App\Http\Resources\Forum;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's post, as the public sees it.
 *
 * `ip_address` is $hidden on the model and absent here — a moderator reads it
 * through the admin resource, and nothing else ever does.
 *
 * @mixin \App\Models\ForumPost
 */
class ForumPostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => (int) $this->id,
            'parent_id'  => $this->parent_id ? (int) $this->parent_id : null,
            'depth'      => (int) $this->depth,
            // Plain text. ForumContent::post() stripped every tag on the way in;
            // whoever renders this escapes it. See that class for why posts are
            // not HTML.
            'body'       => $this->body,
            'created_at' => $this->created_at?->toISOString(),
            'edited_at'  => $this->edited_at?->toISOString(),
            /*
             * One of two authors, decided by the row and not by a role lookup.
             *
             * `user_id` set means the editorial team wrote it, and it publishes
             * under the site's team name with no profile behind it — there is
             * no member page to link to and no post tally that would mean
             * anything.
             *
             * Otherwise it is a member, rendered exactly as it always has been.
             * `is_team` is sent so the frontend can badge staff replies rather
             * than having to infer it from a missing slug.
             */
            'author'     => $this->isStaffAuthored()
                ? [
                    'display_name' => \App\Support\Forum\ForumTeamName::for($this->resource->site),
                    'slug'         => null,
                    'avatar_path'  => null,
                    'posts_count'  => null,
                    'is_team'      => true,
                ]
                : $this->whenLoaded('author', fn (): array => [
                    'display_name' => $this->author->display_name,
                    'slug'         => $this->author->slug,
                    'avatar_path'  => $this->author->avatar_path,
                    'posts_count'  => (int) $this->author->approved_posts_count,
                    'is_team'      => false,
                ]),
            'comments'   => ForumPostResource::collection($this->whenLoaded('comments')),
        ];
    }
}
