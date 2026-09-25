<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RevalidateNextJsSites;
use App\Models\ForumModerationLog;
use App\Models\ForumPost;
use App\Models\ForumReport;
use App\Models\ForumUser;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The moderation queue.
 *
 * This is the screen that gets used daily, so it is built for speed of
 * decision: everything a moderator needs to judge a post is in the listing —
 * the full body, the author, their history, the thread, the time, the reports —
 * so nothing requires opening a second view before clicking approve.
 *
 * Every state change is logged with the acting admin. A moderation system whose
 * actions cannot be attributed is not one.
 */
class ForumModerationController extends Controller
{
    private const int PER_PAGE = 25;

    /** @var list<string> */
    private const array ACTIONS = ['approve', 'reject', 'spam', 'delete', 'restore'];

    /**
     * The queue.
     *
     * Defaults to `pending`, because that is what a moderator opens this screen
     * for. Served by forum_posts_feed_idx — the same index as the public Latest
     * Posts feed, with a different status constant.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_id'   => ['nullable', 'integer', 'exists:sites,id'],
            'status'    => ['nullable', 'string', 'in:' . implode(',', ForumPost::STATUSES)],
            'reported'  => ['nullable', 'boolean'],
            'search'    => ['nullable', 'string', 'max:120'],
            'per_page'  => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $posts = ForumPost::query()
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->where('status', $data['status'] ?? ForumPost::STATUS_PENDING)
            ->when($data['reported'] ?? false, fn ($q) => $q->whereHas('reports', fn ($r) => $r->open()))
            // Free text over bodies. Deliberately NOT indexed: the queue is
            // small by construction (it is the backlog, not the archive) and an
            // index on a TEXT column would cost every insert to serve a search
            // that runs a few times a day.
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where('body', 'like', '%' . $term . '%'))
            ->with([
                'author:id,display_name,slug,email,status,posts_count,approved_posts_count,created_at',
                'article:id,title,slug,forum_category_id',
                'article.category:id,name,slug',
            ])
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->open()])
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? self::PER_PAGE);

        return response()->json([
            'data' => collect($posts->items())->map(fn (ForumPost $p): array => [
                'id'         => (int) $p->id,
                'body'       => $p->body,
                'status'     => $p->status,
                'depth'      => (int) $p->depth,
                'is_comment' => $p->depth === ForumPost::DEPTH_COMMENT,
                'created_at' => $p->created_at?->toISOString(),
                'deleted_at' => $p->deleted_at?->toISOString(),
                'open_reports_count' => (int) $p->open_reports_count,
                // Unpacked from varbinary(16). Moderators only — it is $hidden
                // on the model and never appears in a public resource.
                'ip_address' => $p->ip_address !== null ? @inet_ntop($p->ip_address) : null,
                'author'     => $p->author === null ? null : [
                    'id'            => (int) $p->author->id,
                    'display_name'  => $p->author->display_name,
                    'slug'          => $p->author->slug,
                    'email'         => $p->author->email,
                    'status'        => $p->author->status,
                    'posts_count'   => (int) $p->author->posts_count,
                    'approved_posts_count' => (int) $p->author->approved_posts_count,
                    // "Registered 4 minutes ago with 0 accepted posts" is the
                    // single most useful spam signal on this screen.
                    'registered_at' => $p->author->created_at?->toISOString(),
                ],
                'article'    => $p->article === null ? null : [
                    'id'       => (int) $p->article->id,
                    'title'    => $p->article->title,
                    'slug'     => $p->article->slug,
                    'category' => $p->article->category?->slug,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page'    => $posts->lastPage(),
                'total'        => $posts->total(),
                'per_page'     => $posts->perPage(),
            ],
        ]);
    }

    /**
     * Everything the SITE'S MEMBERS have written, across every status.
     *
     * Deliberately a separate endpoint from index(), not a flag on it, because
     * the two answer different questions. index() is a triage QUEUE: it
     * defaults to pending and exists to be emptied. This is a member-content
     * BROWSE: it defaults to every status, so a moderator can find what
     * somebody posted last week and see what happened to it.
     *
     * `whereNotNull('forum_user_id')` is the whole definition of "member
     * post". A staff reply carries `user_id` instead (see the
     * allow_staff_authored_forum_posts migration), so the same table holds
     * both and only this predicate separates them — which is why it belongs in
     * one place rather than being re-expressed per caller.
     */
    public function memberIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_id'   => ['nullable', 'integer', 'exists:sites,id'],
            'status'    => ['nullable', 'string', 'in:' . implode(',', ForumPost::STATUSES)],
            'member_id' => ['nullable', 'integer', 'exists:forum_users,id'],
            'search'    => ['nullable', 'string', 'max:120'],
            'per_page'  => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $posts = ForumPost::query()
            ->whereNotNull('forum_user_id')
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            // No default: absent means EVERY status, unlike the queue.
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['member_id'] ?? null, fn ($q, $id) => $q->where('forum_user_id', $id))
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where('body', 'like', '%' . $term . '%'))
            ->with([
                'author:id,display_name,slug,email,status,posts_count,approved_posts_count,created_at',
                'article:id,title,slug,forum_category_id',
                'article.category:id,name,slug',
            ])
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->open()])
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? self::PER_PAGE);

        // Totals for the status tabs, scoped by the SAME site filter but not by
        // the status one — a tab that counted only its own selection would read
        // 0 for every tab the moderator is not currently looking at.
        $counts = ForumPost::query()
            ->whereNotNull('forum_user_id')
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => collect($posts->items())->map(fn (ForumPost $p): array => [
                'id'         => (int) $p->id,
                'body'       => $p->body,
                'status'     => $p->status,
                'is_comment' => $p->depth === ForumPost::DEPTH_COMMENT,
                'created_at' => $p->created_at?->toISOString(),
                'edited_at'  => $p->edited_at?->toISOString(),
                'approved_at' => $p->approved_at?->toISOString(),
                'deleted_at' => $p->deleted_at?->toISOString(),
                'open_reports_count' => (int) $p->open_reports_count,
                'site_id'    => (int) $p->site_id,
                'author'     => $p->author === null ? null : [
                    'id'           => (int) $p->author->id,
                    'display_name' => $p->author->display_name,
                    'email'        => $p->author->email,
                    'status'       => $p->author->status,
                    'posts_count'  => (int) $p->author->posts_count,
                    'approved_posts_count' => (int) $p->author->approved_posts_count,
                    'registered_at' => $p->author->created_at?->toISOString(),
                ],
                'article'    => $p->article === null ? null : [
                    'id'       => (int) $p->article->id,
                    'title'    => $p->article->title,
                    'slug'     => $p->article->slug,
                    'category' => $p->article->category?->slug,
                    'board'    => $p->article->category?->name,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page'    => $posts->lastPage(),
                'total'        => $posts->total(),
                'per_page'     => $posts->perPage(),
                'by_status'    => $counts->map(static fn ($v): int => (int) $v),
            ],
        ]);
    }

    /** Badge counts for the sidebar and the screen's tabs. */
    public function counts(Request $request): JsonResponse
    {
        $siteId = $request->integer('site_id') ?: null;

        $base = fn () => ForumPost::query()->when($siteId, fn ($q) => $q->where('site_id', $siteId));

        return response()->json([
            'data' => [
                'pending'  => (clone $base())->where('status', ForumPost::STATUS_PENDING)->count(),
                'reported' => ForumReport::query()->when($siteId, fn ($q) => $q->where('site_id', $siteId))->open()->count(),
                'spam'     => (clone $base())->where('status', ForumPost::STATUS_SPAM)->count(),
            ],
        ]);
    }

    /**
     * Act on one or many posts.
     *
     * Bulk and single share one endpoint because they are the same operation —
     * a separate single-item route would be the identical code with a different
     * arity, and would drift.
     */
    public function act(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1', 'max:100'],
            'ids.*'  => ['integer'],
            'action' => ['required', 'string', 'in:' . implode(',', self::ACTIONS)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $posts = ForumPost::withTrashed()->whereIn('id', $data['ids'])->get();

        abort_if($posts->isEmpty(), Response::HTTP_NOT_FOUND);

        $actorId = $request->user()?->id;
        $siteIds = [];

        // One transaction for the whole batch: a bulk approve that half-applied
        // would leave counters describing a state that never existed.
        DB::transaction(function () use ($posts, $data, $actorId, &$siteIds): void {
            foreach ($posts as $post) {
                $from = $post->status;
                $siteIds[(int) $post->site_id] = true;

                match ($data['action']) {
                    'approve' => $this->transition($post, ForumPost::STATUS_APPROVED),
                    'reject'  => $this->transition($post, ForumPost::STATUS_REJECTED),
                    'spam'    => $this->markSpam($post),
                    'delete'  => $post->delete(),
                    'restore' => $post->restore(),
                };

                ForumModerationLog::record(
                    (int) $post->site_id,
                    $actorId,
                    'post',
                    (int) $post->id,
                    $data['action'],
                    $from,
                    $post->fresh()?->status,
                    $data['reason'] ?? null,
                );
            }

            // Acting on a post resolves the reports that pointed at it —
            // otherwise the reported tab keeps showing work that is done.
            ForumReport::query()
                ->whereIn('forum_post_id', $posts->pluck('id'))
                ->open()
                ->update([
                    'status'     => $data['action'] === 'approve'
                        ? ForumReport::STATUS_DISMISSED
                        : ForumReport::STATUS_ACTIONED,
                    'handled_by' => $actorId,
                    'handled_at' => now(),
                ]);
        });

        foreach (array_keys($siteIds) as $siteId) {
            SiteCache::flushSite($siteId);
            RevalidateNextJsSites::dispatch(['forum'], [$siteId]);
        }

        return response()->json([
            'data' => ['affected' => $posts->count(), 'action' => $data['action']],
        ]);
    }

    /**
     * Ban or mute a member, or lift it.
     *
     * Their posts are NOT bulk-deleted by this. Removing a person's whole
     * history on a mute is disproportionate, and on a ban it is a separate,
     * deliberate action — the moderator selects the posts and deletes them.
     */
    public function member(Request $request, ForumUser $forumUser): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,muted,banned'],
            'until'  => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $from = $forumUser->status;

        $forumUser->forceFill([
            'status'       => $data['status'],
            // Only a mute expires. A ban with an expiry would be a mute wearing
            // the wrong label.
            'banned_until' => $data['status'] === ForumUser::STATUS_MUTED ? ($data['until'] ?? null) : null,
            'ban_reason'   => $data['status'] === ForumUser::STATUS_ACTIVE ? null : ($data['reason'] ?? null),
        ])->save();

        if ($data['status'] === ForumUser::STATUS_BANNED) {
            // Revoke every session. A ban that leaves a live token is advice.
            $forumUser->tokens()->delete();
        }

        ForumModerationLog::record(
            (int) $forumUser->site_id,
            $request->user()?->id,
            'member',
            (int) $forumUser->id,
            'status:' . $data['status'],
            $from,
            $data['status'],
            $data['reason'] ?? null,
        );

        return response()->json([
            'data' => [
                'id'     => (int) $forumUser->id,
                'status' => $forumUser->status,
                'banned_until' => $forumUser->banned_until?->toISOString(),
            ],
        ]);
    }

    /**
     * Registered forum members.
     *
     * The admin's Users screen. Counts come straight off the denormalised
     * columns — `posts_count` and `approved_posts_count` are maintained by the
     * observers, so this list never runs a COUNT over forum_posts.
     */
    public function members(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'site_id'  => ['nullable', 'integer', 'exists:sites,id'],
            'status'   => ['nullable', 'string', 'in:active,muted,banned'],
            'role'     => ['nullable', 'string', 'in:' . implode(',', ForumUser::ROLES)],
            'verified' => ['nullable', 'boolean'],
            'search'   => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $members = ForumUser::query()
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when(isset($filters['verified']), fn ($q) => (bool) $filters['verified']
                ? $q->whereNotNull('email_verified_at')
                : $q->whereNull('email_verified_at'))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where(
                fn ($w) => $w->where('display_name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%"),
            ))
            ->with('site:id,name,slug')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? self::PER_PAGE);

        return response()->json([
            'data' => collect($members->items())->map(fn (ForumUser $m): array => [
                'id'            => (int) $m->id,
                'display_name'  => $m->display_name,
                'slug'          => $m->slug,
                'email'         => $m->email,
                'role'          => $m->role,
                'status'        => $m->status,
                'verified'      => $m->email_verified_at !== null,
                'posts_count'   => (int) $m->posts_count,
                'approved_posts_count' => (int) $m->approved_posts_count,
                'banned_until'  => $m->banned_until?->toISOString(),
                'ban_reason'    => $m->ban_reason,
                'last_seen_at'  => $m->last_seen_at?->toISOString(),
                'created_at'    => $m->created_at?->toISOString(),
                'site'          => $m->site === null ? null : ['id' => (int) $m->site->id, 'name' => $m->site->name],
            ])->all(),
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page'    => $members->lastPage(),
                'total'        => $members->total(),
                'per_page'     => $members->perPage(),
            ],
        ]);
    }

    /**
     * Change a member's role.
     *
     * Separate from the status endpoint, because they are separate axes — see
     * the migration. Logged like every other moderation action.
     */
    public function memberRole(Request $request, ForumUser $forumUser): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:' . implode(',', ForumUser::ROLES)],
        ]);

        $from = $forumUser->role;

        // forceFill: `role` is not fillable, precisely so that no request body
        // anywhere else can set it.
        $forumUser->forceFill(['role' => $data['role']])->save();

        ForumModerationLog::record(
            (int) $forumUser->site_id,
            $request->user()?->id,
            'member',
            (int) $forumUser->id,
            'role:' . $data['role'],
            $from,
            $data['role'],
        );

        return response()->json(['data' => ['id' => (int) $forumUser->id, 'role' => $forumUser->role]]);
    }

    /** One member's posts, for the admin's per-user drill-down. */
    public function memberPosts(Request $request, ForumUser $forumUser): JsonResponse
    {
        $posts = ForumPost::withTrashed()
            ->where('forum_user_id', $forumUser->id)
            ->with(['article:id,title,slug'])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($posts->items())->map(fn (ForumPost $p): array => [
                'id'         => (int) $p->id,
                'body'       => $p->body,
                'status'     => $p->status,
                'created_at' => $p->created_at?->toISOString(),
                'deleted_at' => $p->deleted_at?->toISOString(),
                'article'    => $p->article?->only(['id', 'title', 'slug']),
            ])->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page'    => $posts->lastPage(),
                'total'        => $posts->total(),
            ],
        ]);
    }

    /**
     * Move a post to a status.
     *
     * Goes through the model so ForumPostObserver sees it — writing the column
     * with a query builder would skip every counter.
     */
    private function transition(ForumPost $post, string $status): void
    {
        // Approving something that was soft-deleted has to restore it too, or
        // the counters stay right and the post stays invisible.
        if ($status === ForumPost::STATUS_APPROVED && $post->trashed()) {
            $post->restore();
        }

        $post->status = $status;
        $post->approved_at = $status === ForumPost::STATUS_APPROVED ? now() : null;
        $post->save();
    }

    /**
     * Spam is rejected AND hidden.
     *
     * Two steps rather than one, because they mean different things: the status
     * records the judgement for the author's trust score, the soft delete takes
     * it off the page. Keeping it merely "rejected" would leave it in the
     * moderator's default view forever.
     */
    private function markSpam(ForumPost $post): void
    {
        $this->transition($post, ForumPost::STATUS_SPAM);

        if (! $post->trashed()) {
            $post->delete();
        }
    }
}
