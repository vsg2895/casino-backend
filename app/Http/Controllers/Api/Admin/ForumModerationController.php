<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateForumPostsRequest;
use App\Http\Requests\Admin\UpdateForumMemberRoleRequest;
use App\Http\Requests\Admin\UpdateForumMemberStatusRequest;
use App\Models\ForumPost;
use App\Models\ForumReport;
use App\Models\ForumUser;
use App\Services\Forum\ForumModerationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The moderation queue.
 *
 * This is the screen that gets used daily, so it is built for speed of
 * decision: everything a moderator needs to judge a post is in the listing —
 * the full body, the author, their history, the thread, the time, the reports —
 * so nothing requires opening a second view before clicking approve.
 *
 * The state changes themselves, and their audit log, are
 * {@see ForumModerationService}.
 */
class ForumModerationController extends Controller
{
    private const int PER_PAGE = 25;

    /**
     * The queue.
     *
     * Defaults to `pending`, because that is what a moderator opens this screen
     * for. Served by forum_posts_feed_idx — the same index as the public Latest
     * Posts feed, with a different status constant.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $this->queueFilters($request);

        // simplePaginate: no COUNT here — the screen's total comes from queueCount().
        $posts = $this->filteredQueue($data)
            ->with([
                'author:id,display_name,slug,email,status,posts_count,approved_posts_count,created_at',
                'article:id,title,slug,forum_category_id',
                'article.category:id,name,slug',
            ])
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->open()])
            ->orderByDesc('id')
            ->simplePaginate($data['per_page'] ?? self::PER_PAGE);

        return response()->json([
            'data' => collect($posts->items())->map(fn (ForumPost $p): array => [
                'id' => (int) $p->id,
                'body' => $p->body,
                'status' => $p->status,
                'depth' => (int) $p->depth,
                'is_comment' => $p->depth === ForumPost::DEPTH_COMMENT,
                'created_at' => $p->created_at?->toISOString(),
                'deleted_at' => $p->deleted_at?->toISOString(),
                'open_reports_count' => (int) $p->open_reports_count,
                // Unpacked from varbinary(16). Moderators only — it is $hidden
                // on the model and never appears in a public resource.
                'ip_address' => $p->ip_address !== null ? @inet_ntop($p->ip_address) : null,
                'author' => $p->author === null ? null : [
                    'id' => (int) $p->author->id,
                    'display_name' => $p->author->display_name,
                    'slug' => $p->author->slug,
                    'email' => $p->author->email,
                    'status' => $p->author->status,
                    'posts_count' => (int) $p->author->posts_count,
                    'approved_posts_count' => (int) $p->author->approved_posts_count,
                    // "Registered 4 minutes ago with 0 accepted posts" is the
                    // single most useful spam signal on this screen.
                    'registered_at' => $p->author->created_at?->toISOString(),
                ],
                'article' => $p->article === null ? null : [
                    'id' => (int) $p->article->id,
                    'title' => $p->article->title,
                    'slug' => $p->article->slug,
                    'category' => $p->article->category?->slug,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'per_page' => (int) $posts->perPage(),
                'has_more' => $posts->hasMorePages(),
            ],
        ]);
    }

    /** Queue entries matching the current filters, as a dedicated COUNT. Sizes the paginator. */
    public function queueCount(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filteredQueue($this->queueFilters($request))->count()]);
    }

    /**
     * The queue's validated filters, shared by index() and queueCount().
     *
     * @return array<string, mixed>
     */
    private function queueFilters(Request $request): array
    {
        return $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', ForumPost::STATUSES)],
            'reported' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
    }

    /**
     * The queue's conditions, and nothing else — one definition for the listing
     * and its count, so the paginator can never disagree with the rows.
     *
     * @param  array<string, mixed>  $data
     * @return Builder<ForumPost>
     */
    private function filteredQueue(array $data): Builder
    {
        return ForumPost::query()
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->where('status', $data['status'] ?? ForumPost::STATUS_PENDING)
            ->when($data['reported'] ?? false, fn ($q) => $q->whereHas('reports', fn ($r) => $r->open()))
            // Free text over bodies. Deliberately NOT indexed: the queue is
            // small by construction (it is the backlog, not the archive) and an
            // index on a TEXT column would cost every insert to serve a search
            // that runs a few times a day.
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where('body', 'like', '%'.$term.'%'));
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
        $data = $this->memberPostFilters($request);

        // simplePaginate: no COUNT here — the screen's total comes from
        // memberIndexCount(). The per-status tab totals below are a different
        // question (every status, not this filter) and stay here.
        $posts = $this->filteredMemberPosts($data)
            ->with([
                'author:id,display_name,slug,email,status,posts_count,approved_posts_count,created_at',
                'article:id,title,slug,forum_category_id',
                'article.category:id,name,slug',
            ])
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->open()])
            ->orderByDesc('id')
            ->simplePaginate($data['per_page'] ?? self::PER_PAGE);

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
                'id' => (int) $p->id,
                'body' => $p->body,
                'status' => $p->status,
                'is_comment' => $p->depth === ForumPost::DEPTH_COMMENT,
                'created_at' => $p->created_at?->toISOString(),
                'edited_at' => $p->edited_at?->toISOString(),
                'approved_at' => $p->approved_at?->toISOString(),
                'deleted_at' => $p->deleted_at?->toISOString(),
                'open_reports_count' => (int) $p->open_reports_count,
                'site_id' => (int) $p->site_id,
                'author' => $p->author === null ? null : [
                    'id' => (int) $p->author->id,
                    'display_name' => $p->author->display_name,
                    'email' => $p->author->email,
                    'status' => $p->author->status,
                    'posts_count' => (int) $p->author->posts_count,
                    'approved_posts_count' => (int) $p->author->approved_posts_count,
                    'registered_at' => $p->author->created_at?->toISOString(),
                ],
                'article' => $p->article === null ? null : [
                    'id' => (int) $p->article->id,
                    'title' => $p->article->title,
                    'slug' => $p->article->slug,
                    'category' => $p->article->category?->slug,
                    'board' => $p->article->category?->name,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'per_page' => (int) $posts->perPage(),
                'has_more' => $posts->hasMorePages(),
                'by_status' => $counts->map(static fn ($v): int => (int) $v),
            ],
        ]);
    }

    /** Member posts matching the current filters, as a dedicated COUNT. Sizes the paginator. */
    public function memberIndexCount(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filteredMemberPosts($this->memberPostFilters($request))->count()]);
    }

    /**
     * The member-posts browse's validated filters, shared by memberIndex() and
     * memberIndexCount().
     *
     * @return array<string, mixed>
     */
    private function memberPostFilters(Request $request): array
    {
        return $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', ForumPost::STATUSES)],
            'member_id' => ['nullable', 'integer', 'exists:forum_users,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
    }

    /**
     * The browse's conditions, and nothing else — one definition for the
     * listing and its count.
     *
     * @param  array<string, mixed>  $data
     * @return Builder<ForumPost>
     */
    private function filteredMemberPosts(array $data): Builder
    {
        return ForumPost::query()
            ->whereNotNull('forum_user_id')
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            // No default: absent means EVERY status, unlike the queue.
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['member_id'] ?? null, fn ($q, $id) => $q->where('forum_user_id', $id))
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where('body', 'like', '%'.$term.'%'));
    }

    /** Badge counts for the sidebar and the screen's tabs. */
    public function counts(Request $request): JsonResponse
    {
        $siteId = $request->integer('site_id') ?: null;

        $base = fn () => ForumPost::query()->when($siteId, fn ($q) => $q->where('site_id', $siteId));

        return response()->json([
            'data' => [
                'pending' => (clone $base())->where('status', ForumPost::STATUS_PENDING)->count(),
                'reported' => ForumReport::query()->when($siteId, fn ($q) => $q->where('site_id', $siteId))->open()->count(),
                'spam' => (clone $base())->where('status', ForumPost::STATUS_SPAM)->count(),
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
    public function act(ModerateForumPostsRequest $request, ForumModerationService $moderation): JsonResponse
    {
        $data = $request->validated();

        $posts = ForumPost::withTrashed()->whereIn('id', $data['ids'])->get();

        abort_if($posts->isEmpty(), Response::HTTP_NOT_FOUND);

        $moderation->act($posts, $data['action'], $data['reason'] ?? null, $request->user()?->id);

        return response()->json([
            'data' => ['affected' => $posts->count(), 'action' => $data['action']],
        ]);
    }

    /** Ban or mute a member, or lift it. See {@see ForumModerationService::setMemberStatus()}. */
    public function member(
        UpdateForumMemberStatusRequest $request,
        ForumUser $forumUser,
        ForumModerationService $moderation,
    ): JsonResponse {
        $data = $request->validated();

        $moderation->setMemberStatus(
            $forumUser,
            $data['status'],
            $data['until'] ?? null,
            $data['reason'] ?? null,
            $request->user()?->id,
        );

        return response()->json([
            'data' => [
                'id' => (int) $forumUser->id,
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
        $filters = $this->memberFilters($request);

        // simplePaginate, not paginate: the screen takes its total from
        // membersCount() below, so a COUNT here would be a second one.
        $members = $this->filteredMembers($filters)
            ->with('site:id,name,slug')
            ->orderByDesc('id')
            ->simplePaginate($filters['per_page'] ?? self::PER_PAGE);

        return response()->json([
            'data' => collect($members->items())->map(fn (ForumUser $m): array => [
                'id' => (int) $m->id,
                'display_name' => $m->display_name,
                'slug' => $m->slug,
                'email' => $m->email,
                'role' => $m->role,
                'status' => $m->status,
                'verified' => $m->email_verified_at !== null,
                /*
                 * WHEN they confirmed, not just whether.
                 *
                 * `verified` above is a boolean and drives the "unconfirmed"
                 * tag and the filter; it cannot answer "did this person
                 * confirm, and when" — which is the question asked of this
                 * screen whenever a signup is queried. The column has always
                 * held the timestamp; it simply never reached the admin.
                 */
                'email_verified_at' => $m->email_verified_at?->toISOString(),
                'posts_count' => (int) $m->posts_count,
                'approved_posts_count' => (int) $m->approved_posts_count,
                'banned_until' => $m->banned_until?->toISOString(),
                'ban_reason' => $m->ban_reason,
                'last_seen_at' => $m->last_seen_at?->toISOString(),
                'created_at' => $m->created_at?->toISOString(),
                'site' => $m->site === null ? null : ['id' => (int) $m->site->id, 'name' => $m->site->name],
            ])->all(),
            'meta' => [
                'current_page' => $members->currentPage(),
                'per_page' => (int) $members->perPage(),
                'has_more' => $members->hasMorePages(),
            ],
        ]);
    }

    /**
     * Members matching the current filters, as a dedicated COUNT.
     *
     * Built from {@see filteredMembers()} — the same conditions the listing
     * uses — with no eager load and no ordering. It sizes the Users screen's
     * paginator and its total badge.
     */
    public function membersCount(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filteredMembers($this->memberFilters($request))->count()]);
    }

    /**
     * The Users screen's validated filters. Shared by the listing and the count,
     * so a value one rejects the other rejects too.
     *
     * @return array<string, mixed>
     */
    private function memberFilters(Request $request): array
    {
        return $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'status' => ['nullable', 'string', 'in:active,muted,banned'],
            'role' => ['nullable', 'string', 'in:'.implode(',', ForumUser::ROLES)],
            'verified' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
    }

    /**
     * The member filter conditions, and nothing else. ONE definition shared by
     * the listing and the count, so the paginator can never disagree with the
     * rows it pages over.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ForumUser>
     */
    private function filteredMembers(array $filters): Builder
    {
        return ForumUser::query()
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when(isset($filters['verified']), fn ($q) => (bool) $filters['verified']
                ? $q->whereNotNull('email_verified_at')
                : $q->whereNull('email_verified_at'))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where(
                fn ($w) => $w->where('display_name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%"),
            ));
    }

    /** Change a member's role. See {@see ForumModerationService::setMemberRole()}. */
    public function memberRole(
        UpdateForumMemberRoleRequest $request,
        ForumUser $forumUser,
        ForumModerationService $moderation,
    ): JsonResponse {
        $moderation->setMemberRole($forumUser, $request->validated('role'), $request->user()?->id);

        return response()->json(['data' => ['id' => (int) $forumUser->id, 'role' => $forumUser->role]]);
    }

    /** One member's posts, for the admin's per-user drill-down. */
    public function memberPosts(Request $request, ForumUser $forumUser): JsonResponse
    {
        $posts = ForumPost::withTrashed()
            ->where('forum_user_id', $forumUser->id)
            ->with(['article:id,title,slug'])
            ->orderByDesc('id')
            // simplePaginate: the drawer shows the latest page and no total,
            // so a COUNT of everything the member wrote would be read by nobody.
            ->simplePaginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($posts->items())->map(fn (ForumPost $p): array => [
                'id' => (int) $p->id,
                'body' => $p->body,
                'status' => $p->status,
                'created_at' => $p->created_at?->toISOString(),
                'deleted_at' => $p->deleted_at?->toISOString(),
                'article' => $p->article?->only(['id', 'title', 'slug']),
            ])->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'per_page' => (int) $posts->perPage(),
                'has_more' => $posts->hasMorePages(),
            ],
        ]);
    }
}
