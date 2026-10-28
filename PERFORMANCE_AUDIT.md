# Performance Audit: slow queries (2026-10-26)

Scope: `backend/` (Laravel 13.38, PHP 8.5, MySQL 9.6 locally / MySQL 8 in production).
Rules followed: no git, no behaviour change, no migrations run against MySQL, no data
writes, no caching added, `.env` untouched. Earlier passes are in `REFACTOR_PLAN.md` §2
and §10. This audit builds on them and does not repeat their conclusions.

**Environment checks before anything ran**

| Check | Result |
|---|---|
| Where `.env` points | `DB_HOST=127.0.0.1`, `DB_DATABASE=affilate_db`: the local dev database, so plain `EXPLAIN` was allowed |
| Test database | `phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, so the suite was safe to run |
| Lazy-load detection | Already on outside production (logs, never throws). Unchanged |
| Local data volume | 11 to 506 rows per table. `EXPLAIN` here shows **which index can be used**, not the plan production will pick |
| `performance_schema` digests | Reset since the last pass (server restart); no app traffic to mine, so this pass is static analysis + `EXPLAIN` |

---

## 1. Summary

| # | Finding | file:line | Cause | Severity | Status |
|---|---|---|---|---|---|
| F1 | Unsubscribes CSV export: one `sites` query per row, because `cursor()` ignores `with('site')` | `app/Http/Controllers/Api/Admin/UnsubscribeController.php:83` | N+1 | **High** (grows with every opt-out) | **Fixed** |
| F2 | Email-validation CSV export: same `cursor()` + `with()` N+1 | `app/Http/Controllers/Api/Admin/EmailValidationLogController.php:101` | N+1 | **High** | **Fixed** |
| F3 | No visibility into slow requests or jobs | `app/Providers/AppServiceProvider.php` | Diagnostics | Med | **Fixed** (logging only) |
| F4 | Unsubscribes filtered by site alone: index lookup + full sort of that site's opt-outs | `UnsubscribeController.php:26`, `:83` | Missing index | Med | **Migration created, not run** |
| R1 | Promotion history listing (no site filter) has no index for `ORDER BY sent_date DESC, id DESC`, so it reads and sorts the whole table | `app/Http/Controllers/Api/Admin/PromotionEmailHistoryController.php:28` | Missing index | **High** at production volume | Recommendation only |
| R2 | Admin listings call `paginate()` (one extra `COUNT(*)`) | every admin listing (24) | Pagination & counts | Med | **Fixed** on all 24. 23 screens size their paginator from a `/count` call (10 of those endpoints are new); the Users drawer shows no total |
| R3 | Leading-wildcard `LIKE '%term%'` searches | 12 places (see R3) | Can't use index | Med | Recommendation only |
| R4 | `email LIKE 'x%' OR name LIKE '%x%'` defeats the email index | `app/Models/MailgunReceiver.php:245` | `orWhere` defeats index | Low | Recommendation only |
| R5 | Unescaped user input in `LIKE` (a `%` or `_` acts as a wildcard) | `UniOneReceiver.php:188`, `UniOneSend.php:95`, `ForumModerationController.php:59,141,289`, `UniOneReceiverController.php:285`, `ForumArticleController.php:90` | Correctness (also cost) | Low | Recommendation only |
| R6 | Unsubscribes listing orders by `unsubscribed_at` with no `id` tiebreak, so paging over ties can repeat or skip rows | `UnsubscribeController.php:30` | Ordering | Low | Recommendation only (behaviour change) |
| R7 | Email-validation export orders by `id` under a site filter; only `(site_id, created_at)` exists | `EmailValidationLogController.php:101` | Missing index | Low (export only) | Recommendation only |
| R8 | MySQL slow query log not configured | server | Ops | Med | Manual step (§6) |

**Checked and clean:** no `whereDate` / `whereMonth` / `whereYear` / `DATE(col)` / `LOWER(col)`
predicates remain (earlier passes rewrote them as ranges, with comments).
No `->get()->count()`, `count($q->get())`, or `->count() > 0` used as an existence check.
No plain `chunk()` over rows it modifies (every batch loop is `chunkById`).
Every API Resource relation is behind `whenLoaded` or eager-loaded by every caller. Full
suite with `LOG_CHANNEL=stderr`: **0 lazy-load warnings, 0 slow-request warnings**. Every
high-volume table already has composite indexes in equality-then-sort order (see
`docs/DATABASE.md`, "Indexes").

---

## 2. Applied changes

### F1 + F2: `cursor()` silently drops eager loads

**Why it is slow.** Eloquent's `Builder::cursor()` (vendor `Eloquent/Builder.php:1101`)
hydrates one model at a time and never calls `eagerLoadRelations()`. So
`Unsubscribe::with('site')->cursor()` still runs `select * from sites where id = ?` **once
per exported row**. The test written first measured 6 `sites` queries for 6 rows. An
export of 50,000 opt-outs costs 50,001 queries.

**Why nothing caught it.** Lazy-loading prevention only flags models fetched as part of a
multi-row result. `cursor()` hydrates each model alone, so the detector stays silent.

**New helper:** `app/Support/EagerCursor.php`

```php
public static function of(Builder $query, int $chunkSize = 1000): LazyCollection
{
    return $query->cursor()
        ->chunk($chunkSize)
        ->flatMap(static fn (LazyCollection $models): array => $query->eagerLoadRelations($models->values()->all()));
}
```

**Before / after (Unsubscribe):**

```php
// before
$rows = Unsubscribe::with('site')
    ->when(...)->when(...)
    ->orderByDesc('unsubscribed_at')
    ->cursor()
    ->map(fn (Unsubscribe $u) => [...]);

// after
$rows = EagerCursor::of(
    Unsubscribe::with('site')
        ->when(...)->when(...)
        ->orderByDesc('unsubscribed_at'),
)
    ->map(fn (Unsubscribe $u) => [...]);
```

**Before / after (EmailValidationLog):**

```php
// before
$rows = $this->filtered($request)->with('site:id,name,slug')->latest('id')->cursor();
// after
$rows = EagerCursor::of($this->filtered($request)->with('site:id,name,slug')->latest('id'));
```

**Why behaviour is preserved:**
- **Same rows, same order.** The same single SQL statement runs (`cursor()` is still the
  source), and `chunk()` / `flatMap()` keep stream order.
- **Same values.** `eagerLoadRelations()` is the method `get()` itself uses, so the
  eager load runs exactly as written, including the `site:id,name,slug` column list (the
  export reads only `name`) and `Site`'s `SoftDeletes` scope. A row with a soft-deleted or
  missing site gets `null` either way, and the export writes `''` either way.
- **Still streamed.** The CSV is written row by row, with at most 1,000 models held per
  chunk.
- **Pinned by tests:** `AdminUnsubscribeTest::test_export_loads_sites_without_a_query_per_row`
  and `EmailValidationLogAdminTest::test_the_csv_export_loads_sites_without_a_query_per_row`.
  Both **failed on the old code** (6 queries, expected 1), and both assert each row still
  carries its own site name. The existing export tests pass unchanged.

Other `cursor()` loops checked: `NewsletterController:231` and
`NewsletterPhoneController:304` touch no relations. `SearchIndexer:98`,
`DispatchMailgunReceiverCampaigns:42` and `DispatchDuePromotionSchedules:56` don't read
relations per row in a way an eager load would replace. **Not changed.**

### F3: slow-request warning (logging only, all environments)

`config/database.php`, new key:

```php
'slow_query_threshold_ms' => (int) env('DB_SLOW_QUERY_THRESHOLD_MS', 500),
```

`AppServiceProvider::boot()`:

```php
$slowQueryThreshold = (int) config('database.slow_query_threshold_ms');
if ($slowQueryThreshold > 0) {
    DB::whenQueryingForLongerThan($slowQueryThreshold, function (Connection $connection) use ($slowQueryThreshold): void {
        $request = $this->app->make('request');
        $route = $request->route();

        Log::warning('Slow database work in one request', [
            'total_ms'     => round($connection->totalQueryDuration(), 1),
            'threshold_ms' => $slowQueryThreshold,
            'connection'   => $connection->getName(),
            'route'        => $route !== null
                ? $request->method() . ' ' . $route->uri()
                : 'console: ' . ($_SERVER['argv'][1] ?? '?'),
        ]);
    });
}
```

- **Behaviour-preserving:** it only writes a log line. Nothing is thrown or aborted, and
  no query changes. `.env` is untouched; the default applies until you set the variable.
- **Once per request, once per job.** Laravel's queue worker calls
  `resetTotalQueryDuration()` and `allowQueryDurationHandlersToRunAgain()` after every job
  (`QueueServiceProvider:258`), so a long-lived worker reports each slow job, not just
  its first.
- **No secrets in logs.** It logs the route **pattern** (`POST api/v1/verify/{token}`),
  never the URL: verify and unsubscribe tokens travel in the path. It logs no SQL or
  bindings either (subscriber emails).
- **Not added:** the optional `DB::listen` per-query logger. `APP_DEBUG=true` locally
  would log every query of every request, which buries the signal. The slow query log
  (§6) is the better per-statement tool.
- **Tests:** `tests/Feature/SlowQueryWarningTest.php` (3 tests): no warning under the
  threshold, exactly one past it, and an HTTP request logs the pattern and not the token.
- `Model::preventLazyLoading()` with a logging handler **already existed**. Not changed.

---

## 3. New migration (created, NOT run)

| File | Index | Serves |
|---|---|---|
| `database/migrations/2026_11_17_100000_add_performance_indexes_to_unsubscribes_table.php` | `unsubscribes_site_time_idx (site_id, unsubscribed_at)` | Unsubscribes screen + CSV export filtered by **site alone** |

**Evidence (local `EXPLAIN`):**

```
-> Sort: unsubscribes.unsubscribed_at DESC
    -> Index lookup on unsubscribes using unsubscribes_site_id_email_type_unique (site_id = 1)
```

The existing `(site_id, type, unsubscribed_at)` orders the rows only when a stream is also
chosen. `docs/DATABASE.md` claimed the site-filtered view was covered; it is not without
`type`. Equality first, then the sort column; InnoDB appends `id`.

- `up()` uses `ALTER TABLE … ADD INDEX …, ALGORITHM=INPLACE, LOCK=NONE` on MySQL (the
  statement fails rather than copying the table) and the schema builder elsewhere. It is
  re-runnable. `down()` drops the index. Same structure as the DB-1 migration.
- **Verified without touching MySQL:** `migrate` then `migrate:rollback --step=1` on a
  throwaway SQLite file in the scratchpad (since deleted). The index appeared, then
  disappeared. Local MySQL check afterwards: 0 rows in `migrations` for this file, and the
  index does not exist.

> ⚠️ **Run it manually, in a quiet period, after testing on a copy of production data.**
> `INPLACE, LOCK=NONE` keeps reads and writes running, but the build still reads the
> whole table, takes a brief metadata lock at start and end (which waits behind any long
> transaction), and grows replica lag. Check first:
> `SELECT COUNT(*) FROM unsubscribes;` then `php artisan migrate --pretend`, then
> `php artisan migrate`.

---

## 4. Recommendations (not applied)

### R1: promotion history listing without a site filter · High at volume

`select * from promotion_email_histories where stream = 'campaign' order by sent_date desc, id desc limit 50 offset 0`

Local `EXPLAIN`: `Table scan` → `Filter stream` → `Sort`. No index starts with
`stream, sent_date` or with `sent_date`. This is the one table that reaches millions of
rows, and the page also runs `paginate()`'s `COUNT(*)` (see R2).

**Why not applied:** `2026_11_08_100000_tune_admin_listing_indexes` and
`docs/DATABASE.md` record a deliberate decision that "a local per-partition index cannot
serve a global `ORDER BY sent_date DESC`". MySQL's partition handler can merge ordered
per-partition index scans, so that claim may be wrong. I could not test it without
building the index (an `ALTER`). **Test on a production copy:**

```php
// database/migrations/2026_11_17_110000_add_performance_indexes_to_promotion_email_histories_table.php
// (same structure as the unsubscribes migration)
private const string TABLE = 'promotion_email_histories';
private const string INDEX = 'promo_hist_stream_date_idx';
private const array COLUMNS = ['stream', 'sent_date'];   // InnoDB appends (id, sent_date)
```

Ship it only if `EXPLAIN FORMAT=TREE` of the query above then shows an index scan (reverse)
with **no** `Sort:` line. It costs one more index write per history row (bulk inserts), so
it should earn its place.

### R2: listings that pay for a COUNT nobody reads · Med · **applied (2026-10-26)**

`paginate()` runs a `COUNT(*)` with the listing's filters on every page view. Every
messaging screen *also* called its dedicated `/count` endpoint on every reload (for the
badge), so the same filtered set was counted **twice** per page view. On
`promotion_email_histories` and `newsletters` that COUNT is the most expensive query on
the screen.

**Change:** the listing is `simplePaginate()`, so it runs no COUNT. The screen's paginator
is sized from the `/count` call it already makes beside the listing. Result: one COUNT per
page view instead of two, and the same totals and page numbers on screen.

| Endpoint | Count endpoint (same `filtered()` builder) | Screen |
|---|---|---|
| `EmailValidationLogController::index` (first pass) | `email-validation/count` | `emailValidation/List.vue` |
| `PromotionEmailHistoryController::index` | `promotion-history/count` | `promotionHistory/List.vue` |
| `NewsletterController::index` | `newsletters/count` | `newsletter/List.vue` + `newsletterStore` |
| `UnsubscribeController::index` | `unsubscribes/count` | `unsubscribes/List.vue` |
| `VerifyEmailSendController::index` | `verify-email-sends/count` | `verifyEmailHistory/List.vue` |
| `VerificationPromotionEmailController::history` | `verification-promotion/history/count` | `verificationPromotion/History.vue` |
| `MailgunReceiverController::index` | `mailgun-receivers/count` | `mailgunReceivers/List.vue` |
| `WarmupEmailController::index` | `warmup-emails/count` | `warmup/List.vue` |
| `WarmupEmailController::history` | `warmup-emails/history/count` | `warmup/History.vue` |
| `NewsletterPhoneController::index` | `newsletter-phones/count` | `newsletterPhone/List.vue` |
| `NewsletterPhoneController::history` | `newsletter-phones/history/count` | `smsHistory/List.vue` |
| `ForumModerationController::members` | **new** `forum-members/count` (`membersCount`) | `users/List.vue` |
| `CasinoController::index` | `casinos/count` | `casinos/List.vue` + `casinosStore` |
| `CasinoReviewController::index` | `reviews/count` | `reviews/List.vue` |
| `EmailScheduleController::index` | **new** `schedules/count` | `schedules/List.vue` |
| `CmsPageController::index` (via `CmsPageService` / repository) | **new** `pages/count` (same policy) | `cmsPages/List.vue` + `cmsPagesStore` |
| `ArticleController::index` | **new** `sites/{site}/articles/count?type=` | `sites/Articles.vue` |
| `SiteRevalidationController::index` | **new** `sites/{site}/revalidations/count` | `sites/CacheHealth.vue` |
| `ForumArticleController::index` | **new** `sites/{site}/forum-articles/count` | `forum/Articles.vue` |
| `ForumModerationController::index` (queue) | **new** `forum-posts/count` (`queueCount`) | `forum/Moderation.vue` |
| `ForumModerationController::memberIndex` | **new** `forum-member-posts/count` | `forum/MemberPosts.vue` |
| `UniOneSendController::index` | **new** `unione/sends/count` | `unione/Sends.vue` |
| `UniOneReceiverController::index` | **new** `unione/receivers/count` | `unione/Receivers.vue` |
| `ForumModerationController::memberPosts` | none: the Users drawer shows one page and no total | `users/List.vue` drawer |

**Before / after (each listing):**

```php
// before
return NewsletterResource::collection($query->paginate($perPage));
// after
return NewsletterResource::collection($query->simplePaginate($perPage));
```

Where a listing had no count endpoint, its validation and WHERE clauses moved into a
pair of private methods (`…Filters()` + `filtered…()`) shared by the listing and the new
count. The two therefore cannot disagree, and the count rejects what the listing
rejects. Every literal `count` route is declared before its `{param}` sibling.

**Response change:** `meta.total`, `meta.last_page`, `meta.links` and `links.last` are
gone. `data`, `meta.current_page`, `per_page`, `from`, `to` and `links.next` /
`links.prev` remain. The hand-built endpoints (forum, articles, UniOne) now report
`meta: {current_page, per_page, has_more}` and keep their extra fields: `by_status`,
`published_count`, `most_popular_count` and `featured_count`. No other consumer reads
these fields; the only consumers are the admin screens above (grepped `admin/src` and
`sites/*/src`).

`per_page` on the hand-built endpoints is cast to `int`. Unlike `paginate()`,
`simplePaginate()` keeps the validated query-string value as given, so `?per_page=5`
came back as `"5"`. `AdminNewsListingTest` caught this.

**Admin side:**
- `SimplePaginatedResponse<T>` in `shared/types/pagination.ts`. It is admin-only in
  `scripts/sync-shared-types.sh` and has been synced.
- `utils/pagination.ts`: `pageWindow()` + `pagerMeta()`. These turn a page and its count
  into the `{ total, last_page }` each screen already used.
  - The total is never smaller than the rows on screen, because the two requests run
    concurrently.
  - If the count request fails, the pager shows the rows seen so far plus one, so "next"
    still works one page at a time instead of the table going dead.
  - Anything that quotes a number shows only the exact count, never that estimate:
    "Delete filtered" on the phone list, the UniOne "Total Runs" badge, the Articles
    "All" tile and the Users header.

**Tests:**
- `SimplePaginatedListingsTest` covers all 23 listings that have a count, plus the
  drawer.
  - It records the COUNT the `/count` endpoint runs and asserts the listing never runs
    that same statement. Per-row `withCount` subqueries and the articles' editorial
    counters are legitimate and are not flagged.
  - Verified by reverting the forum queue, member posts and articles to `paginate()`:
    all three cases fail.
- Tests that read `meta.total` now assert against `/count`:
  - `AdminUnsubscribeTest`, `FilteredCountsTest`, `ForumPublicApiTest`,
    `ForumModerationTest`, `AdminListPaginationTest`, `AdminNewsListingTest`,
    `NewsSectionTest`.
  - The three date-filter tests (`HistoryDateFilterTimezoneTest`,
    `ForumArticleDateFilterTest`, `UniOneReceiverDateFilterTest`) now also assert that
    the listing's rows and its count agree for every window.

**Not switched:** nothing. The public site endpoints (category, country, bonus, news and review feeds) keep `paginate()`: they are cached per site and their `meta.total` / `last_page` drive the sites' pagers and SEO pagination links. That is a public contract, out of scope for this pass.

### R3: leading-wildcard `LIKE` · Med

A `%term%` pattern cannot use a B-tree index, so the search reads every row that the other
filters leave:

| Where | Columns |
|---|---|
| `CasinoReview::scopeSearch` (`app/Models/CasinoReview.php:139`) | `author_name`, `title`, **`body` (TEXT)** |
| `MailgunReceiver` (`:246`) | `name` |
| `NewsletterBasedOnPhone` (`:86`), `PhoneSmsHistory` (`:94`) | `phone`, when the term has no leading `+` |
| `UniOneSend` (`:95`) | `subject` |
| `UniOneReceiver` (`:188`) | `email`, `name` |
| `ForumModerationController` (`:59`, `:141`, `:289`) | `body`, `display_name`, `email` |
| `UnsubscribeController` (`:61`) | `email` |
| `ForumArticleController` (`:90`) | `title` |
| `UniOneReceiverController` (`:285`) | `consent_source` |

Options, a product decision per screen:
(a) **prefix-only** (`term%`), already the pattern in `NewsletterController`, `PromotionEmailHistoryController`, `WarmupEmail`;
(b) **FULLTEXT** + `MATCH … AGAINST` (as `search_index` does), for the TEXT columns;
(c) Meilisearch/Scout.
For most of these, (a) is enough: prefix match by default, contains-match only when the
term starts with `@` (the newsletter screen's existing convention).

### R4: `OR` across an indexed prefix and a wildcard · Low

`MailgunReceiver` search: `email LIKE 'x%' OR name LIKE '%x%'`. The `OR` forces a scan
even though `email` is indexed. Split it on whether the term looks like an address:

```php
return str_contains($term, '@')
    ? $query->where('email', 'like', $escaped . '%')
    : $query->where(fn ($q) => $q->where('email', 'like', $escaped . '%')->orWhere('name', 'like', '%' . $escaped . '%'));
```

(Changes which rows match a term containing `@`, so it's a product call.)

### R5: escape LIKE metacharacters · Low

Same helper the rest of the codebase uses:

```php
$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
```

A user typing `_` today matches any character, and a lone `%` matches every row. That's a
correctness fix that changes results, so not applied.

### R6: unsubscribes paging tiebreak · Low

```php
->orderByDesc('unsubscribed_at')->orderByDesc('id')
```

Bulk opt-outs can share a timestamp. Without the tiebreak, MySQL may return ties in a
different order on each page. The new `(site_id, unsubscribed_at)` index and the existing
`(unsubscribed_at, id)` index both serve the extra column for free. Not applied because
it changes the row order among ties.

### R7: email-validation export under a site filter · Low

Export sorts by `id` and the site filter rides `(site_id, created_at)`, so the export sorts
in memory. Exports are rare; an index `(site_id, id)` (effectively `(site_id)`) costs a
write on every validation. **Leave it** unless the export becomes slow.

---

## 5. EXPLAIN checklist (top 10 suspect queries)

Paste into MySQL **with plain `EXPLAIN` or `EXPLAIN FORMAT=TREE` in front, never
`EXPLAIN ANALYZE`** (that executes the query). Replace `1` with a real site id.

**What to look for:** `type=ALL` / "Table scan" (full scan); `key=NULL` (no index);
`rows` far above the rows returned; `Using filesort` / a `Sort:` line (sorting instead of
index order); `Using temporary` (temp table, typical for GROUP BY).

| # | Query | Good plan |
|---|---|---|
| 1 | `select * from promotion_email_histories where stream = 'campaign' order by sent_date desc, id desc limit 50;` | **Today:** scan + sort (R1). After R1: reverse index scan, no Sort |
| 2 | `select count(*) from promotion_email_histories where stream = 'campaign' and sent_date >= '2026-10-01';` | `partitions` lists only the months in range |
| 3 | `select * from unsubscribes where site_id = 1 order by unsubscribed_at desc limit 50;` | Before the migration: Sort line. After: `unsubscribes_site_time_idx` (reverse), no Sort |
| 4 | `select * from newsletters where site_id = 1 and deleted_at is null order by created_at desc, id desc limit 50;` | `newsletters_site_id_created_at_index` (reverse), no Sort |
| 5 | `select count(*) from newsletters where site_id = 1 and deleted_at is null;` | index-only (`Using index`); `rows` ≈ subscribers of that site |
| 6 | `select casinos.*, pivot.affiliate_url, pivot.position, pivot.featured from casinos inner join casino_site as pivot on casinos.id = pivot.casino_id where pivot.site_id = 1 and pivot.active = 1 and casinos.active = 1 and casinos.deleted_at is null order by pivot.position;` | At volume, driven from `casino_site_site_active_position_index`. Locally the planner starts from `casinos` (7 rows), which is fine |
| 7 | `select casino_id, max(published_at) from casino_reviews where site_id = 1 and status = 'published' group by casino_id order by max(published_at) desc limit 10;` | `casino_reviews_site_status_casino_pub_idx`, no `Using temporary` for the grouping |
| 8 | `select id from forum_articles where site_id = 1 and status = 'published' order by hot_score desc, id desc limit 20;` | Covering range scan on `forum_articles_hot_idx` (confirmed locally) |
| 9 | `select id from search_index where site_id = 1 and is_active = 1 and match(title, body) against ('bonus*' in boolean mode) order by (match(title, body) against ('bonus*' in boolean mode)) * weight desc limit 10;` | `Full-text index search` (confirmed locally); a sort of the matches is expected |
| 10 | `select * from casino_reviews where (author_name like '%bonus%' or title like '%bonus%' or body like '%bonus%') order by id desc limit 25;` | **Today:** reverse PK scan + filter, reading rows until 25 match (R3). Worst case is a term that matches nothing: the whole table |

Also on production, after a week of uptime (read-only):

```sql
SELECT * FROM sys.statements_with_full_table_scans WHERE db = '<db>' ORDER BY exec_count DESC LIMIT 20;
SELECT * FROM sys.statements_with_sorting          WHERE db = '<db>' ORDER BY exec_count DESC LIMIT 20;
SELECT * FROM sys.schema_unused_indexes            WHERE object_schema = '<db>';
```

---

## 6. Manual next steps: slow query log

Add to `my.cnf` under `[mysqld]` (on Docker, mount it as a file in `/etc/mysql/conf.d/`),
then restart MySQL in a quiet window:

```ini
slow_query_log                = 1
slow_query_log_file           = /var/log/mysql/mysql-slow.log
long_query_time               = 1
log_queries_not_using_indexes = 0   # off initially: on small tables it floods the log
min_examined_row_limit        = 0
log_slow_admin_statements     = 0
```

Make sure the log directory exists and is writable by `mysql`, and add it to logrotate.
Once the log is quiet and understood, lower `long_query_time` to `0.5`. Turn
`log_queries_not_using_indexes = 1` on for a short window only, together with
`log_throttle_queries_not_using_indexes = 60`.

**Analyse, sorted by TOTAL time** (count × average). A 50 ms query run 100,000 times
outweighs a 5 s query run twice:

```bash
# Bundled with MySQL. -s t sorts by total time, -t 20 keeps the top 20.
mysqldumpslow -s t -t 20 /var/log/mysql/mysql-slow.log

# Percona Toolkit. Its default ranking is already total response time.
pt-query-digest /var/log/mysql/mysql-slow.log > slow-report.txt
pt-query-digest --since '24h' --limit 20 /var/log/mysql/mysql-slow.log
```

In the `pt-query-digest` report, read the profile table first: **Response time** (total)
and **Calls**, then the per-query "Rows examine" vs "Rows sent". A large ratio means a
missing or unused index. Cross-check against the `Slow database work in one request`
warnings now in `laravel.log`, which give the route.

---

## 7. Verification

| Gate | Result |
|---|---|
| `php -l` over `app routes database config tests` | clean |
| New tests, run against the old code first | both N+1 tests **failed** (6 queries vs 1), then passed after the fix |
| Full suite (SQLite `:memory:`) | **1,361 tests / 4,857 assertions green** after the R2 rollout (was 1,331) |
| `cd admin && npm run build` (after R2) | passes |
| Suite with `LOG_CHANNEL=stderr` | 0 `Lazy loaded`, 0 `Slow database work` |
| Migration | up + down on a scratch SQLite file only; **not run on MySQL** (confirmed absent) |
| git | not used |
