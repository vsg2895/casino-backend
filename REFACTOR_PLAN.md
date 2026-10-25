# Backend Refactoring & Optimization Plan

_Drafted 2026-10-24. Scope: `backend/` only. **Status: steps 0–7 executed on branch
`refactor/backend-plan`; step 7 covered 5 of the 6 listed controllers.** See §9. A second,
independent audit pass on the same day is in **§10**; its changes are uncommitted._

---

## 0. Ground rules for this plan

**Behaviour freeze.** Every item below must leave routes, response shapes, field names,
validation rules, status codes, side effects (mail, jobs, cache, revalidation) and data
exactly as they are. Anything I could not prove safe is in **§5 Needs review**, not in the
work list.

**Risk levels**

| Level  | Meaning                                                                                                                                      |
|--------|----------------------------------------------------------------------------------------------------------------------------------------------|
| Low    | Mechanical; the same SQL / same output can be shown by a test or by `toRawSql()` comparison.                                                 |
| Medium | Moves code across a boundary (controller → Form Request / trait / scope). Equivalence relies on tests that exist or that Phase 0 adds first. |
| High   | Touches the tenancy/security boundary or runtime config. Needs your explicit sign-off even after this plan is approved.                      |

**Per-module workflow (after approval)**
1. Write or extend the characterization tests for the module first, and run them green against the **unchanged** code.
2. Refactor.
3. `vendor/bin/pint --dirty --format agent` → `php -l` gate → `php artisan test --compact` (full suite).
4. Stop on the first failure. One Conventional Commit per module, staging only that module's files. The pre-existing changes to `CLAUDE.md`, `composer.json` and `composer.lock` are never staged.

### Verified environment

| Thing                       | Version                              | Source                   |
|-----------------------------|--------------------------------------|--------------------------|
| PHP (CLI)                   | 8.5.5 (`composer.json` floor `^8.3`) | `php -v`                 |
| laravel/framework           | 13.38.0                              | `composer show --direct` |
| laravel/sanctum             | 4.3.2                                | 〃                        |
| spatie/laravel-permission   | 8.0.0                                | 〃                        |
| spatie/laravel-medialibrary | 11.23.0                              | 〃                        |
| spatie/laravel-sluggable    | 4.0.2                                | 〃                        |
| phpunit/phpunit             | 12.5.30                              | 〃                        |
| MySQL (local)               | **9.6.0**                            | `SELECT VERSION()`       |

> ⚠ The project docs say production runs **MySQL 8**, but the local server is **9.6**. Every
> index and EXPLAIN in this plan uses only syntax that works on both. Final EXPLAINs should
> still be repeated against a MySQL 8 production snapshot (see §2.5).

### Baseline

- **Test suite: 1,199 tests / 4,166 assertions, all passing** (`php artisan test --compact`, ~20 s).
- Tests run on **SQLite `:memory:`** (`phpunit.xml`). They prove PHP behaviour and response
  shape, but **not** MySQL query plans, index usage, window functions or FULLTEXT. The
  database items are therefore verified separately, with EXPLAIN on MySQL.
- All 167 migrations have run, so none of them will be edited.

---

## 1. Summary of findings

Earlier tuning passes (e.g. `tune_hot_path_indexes`, `tune_admin_listing_indexes`,
`AdminListingPerformanceTest`) have already done most of the obvious work. The remaining
opportunities are fewer than in a typical audit, but some are significant:

| # | Finding                                                                                                                                                                       | Impact                               | Risk                 |
|---|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------|----------------------|
| A | `VerifySiteAccess` runs a **bcrypt cost-12 `Hash::check` on every public request**: **~192 ms measured**, paid even when `SiteCache` hits                                     | **Largest latency item in the API**  | High → §5.1          |
| B | Public review feed and per-casino review listing have no index matching `site_id + status + casino_id` → ORDER BY `published_at`                                              | Medium, grows with reviews           | Low (additive index) |
| C | The "casino attached to and active on this site" predicate is copy-pasted **12 times**                                                                                        | Maintainability; divergence risk     | Low                  |
| D | 45 inline `->validate()` calls in 19 controllers (corrected: the first count also matched `$request->validated()`), against the project's "Form Request for every write" rule | Convention / testability             | Medium               |
| E | Receiver-campaign endpoints duplicated between `MailgunKeyController` and `SmtpCredentialController` (6 methods each)                                                         | Maintainability                      | Medium               |
| F | 5 production jobs declare no `$timeout`, against the project rule "timeout below `retry_after`"                                                                               | Correctness hazard (duplicate sends) | Needs review         |
| G | No lazy-loading detection in development                                                                                                                                      | N+1 regressions go unnoticed         | Low                  |
| H | MySQL `sys.schema_redundant_indexes` reports **zero** redundant indexes; every foreign key is indexed                                                                         | —                                    | —                    |

---

## 2. Database

All index changes go into **new** migrations with a working `down()`, built with
Schema-builder calls only, so they run on MySQL and SQLite alike. Column types, nullability,
defaults and data are never touched.

### 2.1 What was inspected

- Every index in the schema (`information_schema.STATISTICS`): 85 tables.
- `sys.schema_redundant_indexes` → **empty**: no index is a left-prefix duplicate of another.
- Every FK has a backing index (InnoDB enforces this; confirmed in the listing).
- EXPLAIN on the hot public paths: casino listing, special-offer listing, review feed.
- Code scan for queries inside loops, `get()->count()`, `SELECT *` on wide tables, and
  missing chunking (§2.4).

> **Caveat:** the local tables hold 1–500 rows. EXPLAIN shows **which index the optimizer
> can use**, not the plan it will pick at production volume. §2.5 lists the production checks.

### 2.2 Proposed index changes (low → high risk)

#### DB-1 · Add `casino_reviews (site_id, status, casino_id, published_at)` — **Low**

- **Migration:** `YYYY_MM_DD_100000_add_public_feed_index_to_casino_reviews.php`
  - `up()`: `$table->index(['site_id', 'status', 'casino_id', 'published_at'], 'casino_reviews_site_status_casino_pub_idx');`
  - `down()`: `$table->dropIndex('casino_reviews_site_status_casino_pub_idx');`
- **Queries it speeds up**
  1. `Public\CasinoReviewController::feed()`: `WHERE site_id=? AND status='published' … GROUP BY casino_id ORDER BY MAX(published_at)` and its `COUNT/AVG` totals query.
     **Current EXPLAIN:** `Index lookup on casino_reviews using casino_reviews_site_id_index (site_id = 1)` → `Filter: status = 'published'` → `Aggregate using temporary table`. Every review on the site is read, then filtered by status.
     **With the index:** equality on `(site_id, status)` and grouping in `casino_id` order straight off the index. `MAX(published_at)` is read from the index.
  2. The per-casino preview inside the same loop and `Public\CasinoReviewController::index()`: `WHERE casino_id=? AND site_id=? AND status='published' ORDER BY published_at DESC, id DESC`.
     The existing `casino_reviews_public_idx` is `(casino_id, site_id, status, created_at)`. Its last column is **`created_at`**, but these queries sort by **`published_at`**, so today they filesort. With the new index all three equalities plus the sort are index-served; InnoDB appends `id` to every secondary index, so the `id DESC` tie-break is free too.
- **Column order rationale:** `site_id` and `status` are equality filters in **every** caller. `casino_id` comes next because the feed groups by it and the listing filters on it. `published_at` comes last for range and sort.
- **Why behaviour is unchanged:** an index never changes a result set, and ORDER BY is fully specified (`published_at, id`), so no tie ordering can change.
- **Verification:** EXPLAIN before and after on MySQL; the full suite on SQLite.

#### DB-2 · Drop `casino_reviews_site_id_index` after DB-1 — **Rejected (2026-10-24)**

Once DB-1 exists, `(site_id)` is a left prefix of it, and `sys.schema_redundant_indexes`
lists it as redundant. **It is not.** InnoDB appends the primary key to every secondary
index, so `(site_id)` is effectively `(site_id, id)`. That makes it the only index serving the
admin review list filtered by site (`WHERE site_id = ? ORDER BY id DESC LIMIT 25`). EXPLAIN
with it ignored shows MySQL walking the whole table's PRIMARY key and filtering `site_id` row by
row, approaching a full scan for a small site at volume. `sys` compares column prefixes only and
cannot see that. Kept, on your decision; the saving (one index write per review) does not justify
it.

#### Considered and rejected

| Candidate                                                                                      | Why not                                                                                                                                                                                                                                                                                                                                  |
|------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Make `SpecialOffer::scopeClaimable` sargable (`date(expires_at) >= ?` → `expires_at >= ?`)     | `expires_at` is a `DATE` column, so the rewrite is equivalent on MySQL. But EXPLAIN shows the planner reaches `special_offers` through `special_offers_casino_sort_index (casino_id)`, so the date predicate is a post-filter on ~4 rows per casino. No measurable gain, and nonzero risk because SQLite tests compare dates as strings. |
| New index on `casino_site` for the public casino listing                                       | `casino_site_site_active_position_index (site_id, active, position)` already exists and matches the WHERE and ORDER BY. The local planner drives from `casinos` only because there are 7 rows.                                                                                                                                           |
| Merge/drop any of the 8 `email_validation_logs` indexes                                        | Each one backs a distinct admin filter or stats query (`verdict`, `outcome`, `reason_code`, `score`, `quota_month`, `site_id+created_at`, `email`). None are redundant per `sys`.                                                                                                                                                        |
| Drop `search_index_title_fulltext` (looks duplicated by `search_index_fulltext (title, body)`) | Not redundant: `MATCH()` must name the **exact** column list of a FULLTEXT index, so the two serve different queries.                                                                                                                                                                                                                    |
| Indexes on `promotion_email_histories`                                                         | Partitioned table; `promo_hist_unique (site_id, email, sent_date, status)` already serves `sentWithinDayAmong()`.                                                                                                                                                                                                                        |

### 2.3 Query refactors (same result set guaranteed)

#### DB-3 · Remove the duplicate `casinos.deleted_at IS NULL` — **Low**

`Public\CasinoController::baseQuery()` and `CasinoFacetService::casinoIds()` are
**Eloquent** builders that call `->whereNull('casinos.deleted_at')` explicitly **and**
inherit the `SoftDeletes` global scope. The generated SQL contains the predicate twice
(confirmed with `toRawSql()`). Removing the explicit call leaves one identical predicate.
The global scope is never disabled on these builders (no `withTrashed()`), so the result is
identical. Cosmetic in SQL terms: the optimizer already dedupes it.

**Must stay:** the same call in `Public\CountryController` (line 87) and
`SearchIndexer` (lines 446, 465). Those are `DB::table()` builders, which apply **no**
global scopes, so their explicit predicate is the only soft-delete filter.

#### DB-4 · Bulk-update reorder endpoints — **Needs review** (low value)

`Admin\CategoryController::reorder` and `Admin\NavItemController::reorder` issue one
`UPDATE` per id. A single `UPDATE … SET position = CASE id … END` is equivalent for rows and
values, but each per-row update today stamps its own `updated_at` (microseconds apart),
while a single statement would stamp them identically. These lists hold fewer than 100 rows
and change rarely. **Recommendation: leave as is.**

### 2.4 N+1 / loop audit results

| Location                                                                                                                       | Pattern                                                 | Verdict                                                                                                                                                                                                                  |
|--------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Public\CasinoReviewController::feed`                                                                                          | One preview query per casino on the page                | **Keep.** Documented decision, bounded by page size, cached 1 h. An eager-load with `limit()` (Laravel 13 windowed eager limit) is possible but is a readability trade the original author explicitly rejected.          |
| `Admin\CasinoReviewController` / `ForumModerationController` bulk actions                                                      | `$model->delete()` per row                              | **Keep.** Must fire model observers (search index, forum counters). A bulk query delete would skip them, which is a behaviour change.                                                                                    |
| `ReceiverCampaignSender`                                                                                                       | Per-receiver claim/insert/update                        | **Keep.** Atomic per-recipient claims are the duplicate-send guard.                                                                                                                                                      |
| `Services\CasinoProfileImportService`                                                                                          | `Casino::where('slug')->first()` per spreadsheet row    | Candidate for prefetch-by-slug. **Low value** (admin import, small sheets). Listed, not scheduled.                                                                                                                       |
| `SpecialOfferResource`                                                                                                         | `$this->bonusCategory?->slug` (not behind `whenLoaded`) | **Safe today.** Every caller eager-loads `bonusCategory`: admin index/show/store/update/duplicate, public offers, bonus pages, casino resources. Covered by G below so a future caller that forgets it is caught in dev. |
| Other resources                                                                                                                | Relations behind `whenLoaded`                           | Clean.                                                                                                                                                                                                                   |
| Recipient streaming (`ScheduleRecipientService`, `MailgunReceiverSelector`, `PhoneRecipientService`, `WarmupRecipientService`) | Keyset pagination with explicit column lists            | Already optimal.                                                                                                                                                                                                         |

No `get()->count()`, `count($q->get())`, `::all()->filter` or `SELECT *`-then-filter
patterns were found.

### 2.5 Production-side checks (read-only; recommended before DB-1 ships)

1. `EXPLAIN FORMAT=TREE` of the feed query on a MySQL 8 production snapshot, before and after DB-1.
2. `SELECT * FROM sys.schema_unused_indexes WHERE object_schema = '<db>'` on production after at least a week of uptime. It's meaningless on a dev box that was restarted recently.
3. `SELECT * FROM sys.statements_with_full_table_scans ORDER BY exec_count DESC LIMIT 20` to find anything this static audit missed.

---

## 3. Phase 0 — Characterization tests (written first, before any refactor)

These lock in the current behaviour of code that has **no** test coverage today. Each test
is written and run green against the unchanged code, then committed on its own
(`test: lock current behaviour of …`).

| Module touched later                                                                                                                                                    | Existing coverage                                   | Tests to add                                                                                                                                                                                                                                                |
|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| §4.2 scopes (C)                                                                                                                                                         | Public casino/catalog/bonus tests exist             | **Unit:** for each of the 12 call sites, build the old inline query and the new scoped query and assert identical `toRawSql()`. This is the strongest equivalence proof available.                                                                          |
| §5.1 `VerifySiteAccess`                                                                                                                                                 | `VerifySiteAccessTest` (5 cases)                    | Add: rotated key rejected **immediately** after a successful (cache-warm) request; site deactivated after a warm request returns 404; site A's key rejected on site B; status-code precedence 401 → 404 → 403.                                              |
| §4.4 Form Requests: `MailgunKeyController`, `SmtpCredentialController`, `TwilioConfigController`, `SmsTemplateController`, `SeoTemplateController`, `NavItemController` | **None** (no test hits these routes)                | Per endpoint: (a) happy path with `assertExactJson` / exact key set, (b) one 422 per rule with `assertJsonValidationErrors([...])` pinning the **error keys and messages**, (c) 404 on an unknown id, to pin ordering between route binding and validation. |
| §4.5 receiver-campaign trait                                                                                                                                            | 1 test touches `receiver-settings`                  | Each of the 6 endpoints × {Mailgun, SMTP}: exact JSON keys. The SMTP response has **no** `send_enabled`, and that difference must survive.                                                                                                                  |
| DB-1                                                                                                                                                                    | Review feed covered by `ReviewAutoPublishTest` etc. | None needed: an index cannot change results. EXPLAIN is the check.                                                                                                                                                                                          |

Missing factories (`MailgunKey`, `SmtpCredential`, `TwilioConfig`, `SmsTemplate`,
`SeoTemplate`, `NavItem`) will be added under `database/factories/`. They are test-only
infrastructure and don't change runtime behaviour.

---

## 4. Refactoring work list, by module (low → high risk)

### 4.1 Bootstrap & providers — **Low**

| Item                              | Now                                                                                                                                        | After                                                                                                                                                                                                                   | Files                                  | Why behaviour is unchanged                                                                                                                       |
|-----------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------|
| G. Lazy-loading **detection**     | Nothing                                                                                                                                    | `Model::preventLazyLoading(! $this->app->isProduction())` **plus** `Model::handleLazyLoadingViolationUsing(...)` that only **logs** (`Log::warning`). Per the Laravel 13 docs, a custom handler replaces the exception. | `app/Providers/AppServiceProvider.php` | Production: disabled. Dev/tests: logs only, never throws, so no request outcome changes. Tests log to the `null` channel.                        |
| Misplaced docblock                | The "Public newsletter subscribe limiter" comment sits above the `forum-post` limiter, ~60 lines from the `subscribe` limiter it describes | Move it next to `RateLimiter::for('subscribe', …)`                                                                                                                                                                      | `AppServiceProvider.php`               | Comment only.                                                                                                                                    |
| `declare(strict_types=1)` missing | `app/Models/User.php`, `app/Http/Controllers/Controller.php`                                                                               | Add it                                                                                                                                                                                                                  | 2 files                                | Neither file has a typed call whose arguments could be coerced. Verified by reading both. Config files and old migrations are **excluded** (§5). |

Commit: `refactor(bootstrap): log lazy-loading violations outside production`

### 4.2 Shared query scopes — **Low**

| Item                               | Now                                                                                                           | After                                                                                                                                                                 | Files                                                                                                                                                                                  |
|------------------------------------|---------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| C. "Casino published by this site" | `whereHas('sites', fn ($q) => $q->where('sites.id', $id)->where('casino_site.active', true))` copy-pasted 12× | `Casino::scopeAttachedToSite(Builder $q, int $siteId)`, written in the **existing `scopeX` style** (the codebase has 85 `scopeX` methods and no `#[Scope]`; see §5.4) | `Models/Casino.php`; call sites in `Public/{CasinoReview,AffiliateClick,Bonus,Category,SpecialOffer}Controller`, `Services/Search/SearchIndexer`, `Console/Commands/DiagnoseBonusArea` |
| Offer "visible on this site"       | Same 4-line closure inlined 3× in `Public\BonusController` (index count, index eager load, show)              | One private method or `SpecialOffer` scope used by those 3 sites                                                                                                      | `Public/BonusController.php` (+ `Models/SpecialOffer.php`)                                                                                                                             |

**Why behaviour is unchanged:** the scope emits byte-identical SQL, and the Phase 0 unit
test proves it with `toRawSql()` per call site. **Deliberately NOT merged:**
`Public\SpecialOfferController::baseQuery()` looks the same but does **not** check
`casinos.active`. Unifying it would change which offers appear (see §6).

Commit: `refactor(queries): extract casino site-attachment scope`

### 4.3 Public controllers: dead-predicate cleanup — **Low**

DB-3 (duplicate soft-delete predicate in `Public\CasinoController` and `CasinoFacetService` only). Same commit as 4.2 or its own:
`refactor(public): drop duplicate soft-delete predicate`.

### 4.4 Inline validation → Form Requests — **Medium** (done in batches)

**Now:** 45 inline `->validate()` calls in 19 controllers. _(Corrected during execution: the
first count's regex also matched `$request->validated()`, i.e. code already on Form Requests.)_
Of the 45, the read-only GET filters (`index`/`filters` query strings) stay inline because
the project rule covers **writes**, and five methods are excluded by rule 1 below:
`ForumArticleController::storePost`/`updatePost`, `ForumPostController::update`,
`ForumAuthController::google` and `EmailValidationCheckController::store`. Found while
executing and also excluded by rule 1: `ForumTaxonomyController` (updates 404 via
`assertOwns()` first), `ForumAuthController::login/forgotPassword/resetPassword` and
`ForumPostController::report` (their `site()` helper 404s when the feature is off). Each of
those would turn a 404 into a 422 for an invalid body. **After:**
`Store{X}Request` / `Update{X}Request` classes under `app/Http/Requests/{Admin,Public}`,
following the existing naming convention.

**Equivalence rules (per method; any method that fails one stays as is and goes to §5):**
1. `validate()` must be the **first** statement that can produce a response. A Form Request
   validates before the method body runs, so a method that aborts with 404/409 before
   validating would change status-code precedence.
2. The rules array moves **verbatim**. Conditional rules (`Rule::when`, rules built from
   `$request` values or the bound model) move into `rules()` using `$this->route(...)`, with
   the same values.
3. `authorize()` returns `true`. Auth is already enforced by `auth:sanctum` / `verify.site`.
4. Custom `messages()` / `attributes()` move verbatim, so 422 bodies stay byte-identical.
5. `$validated = $request->validate(...)` becomes `$validated = $request->validated()`.
   This returns the same array.

**Batches (one commit each, suite run between):**
1. Content admin: `Category`, `BonusCategory`, `NewsCategory`, `Country`, `CmsPage`, `SocialLink`, `Redirect`, `SeoTemplate`, `NavItem`, `Site`, `SiteForum`
2. Messaging credentials: `SendgridKey`, `MailgunKey`, `SmtpCredential`, `TwilioConfig`, `SmsTemplate`, `EmailSchedule`
3. Templates: `SiteEmailTemplate`, `SiteVerifyEmail`, `SitePromotionEmail`, `VerificationPromotionEmail`
4. Audiences: `Newsletter`, `NewsletterPhone`, `MailgunReceiver`, `WarmupEmail`, `UniOne*`
5. Forum + public: `ForumArticle`, `ForumModeration`, `ForumTaxonomy`, `Public/*`

The public batch goes last because those endpoints carry throttles, and their 422 bodies
are read by the Next.js sites.

Commits: `refactor(validation): move <batch> validation to form requests`

### 4.5 Receiver-campaign duplication (Mailgun / SMTP) — **Medium**

**Now:** `receiverSettings`, `updateReceiverSettings`, `receiverTemplateSource`,
`previewReceiverMessage`, `previewReceiverBatch` and `runReceiverCampaign` are near-copies in
`Admin\MailgunKeyController` and `Admin\SmtpCredentialController` (diffed: they differ only
in model type, campaign job class and one response key).

**After:** a controller concern, `Http/Controllers/Concerns/ManagesReceiverCampaign`,
holding the shared body, with the per-credential differences supplied by small hooks:
the job class, and an "extra settings keys" hook that returns `['send_enabled' => …]` for
Mailgun and `[]` for SMTP. Public method names and signatures stay on each controller, so
route bindings (`{mailgun_key}` / `{smtp_credential}`) are untouched.

**Not merged:** `SendMailgunReceiverBatchJob` vs `SendSmtpReceiverBatchJob` (71 diff
lines) and the two campaign jobs (116 diff lines). Their differences are in send semantics,
so they go to §5.

**Why behaviour is unchanged:** Phase 0 pins the exact JSON for all 12 endpoint/credential
pairs, including key order (JSON key order is part of an `assertExactJson`-style snapshot).

Commit: `refactor(messaging): share receiver-campaign endpoints between credentials`

### 4.6 Fat controllers → services — **[DONE 2026-10-24 on your sign-off; 5 of 6, see §9]**

`WarmupEmailController` (781 lines, 26 methods), `ForumModerationController` (504),
`NewsletterPhoneController` (490), `VerificationPromotionEmailController` (485),
`ForumAuthController` (476) and `MailgunKeyController` (445) hold query and business logic
that the project convention ("Form Request → Service → Resource") puts in services.

Extracting that logic is a pure move, but it touches the messaging subsystem, which
`docs/MESSAGING.md` calls the least guessable part of the codebase. I'd do it only after
4.4/4.5 have landed and only for one controller per commit, starting with
`WarmupEmailController` (10 existing warmup test files cover it).

**What moved, and what deliberately stayed.** Business logic (locks, transactions, state
changes, sends, audit logs) moved; request parsing, status-code choice and response shaping
stayed in the controllers, as did the read-only listings (query-string filters → builder →
inline array), because converting those to Resources would be a shape-risking rewrite, not a
move. `MailgunKeyController` was **not** done: after §4.5 its remaining logic is the key test,
which has the same shape as `SendgridKeyController::test` and depends on the controller concern
`ResolvesTestSubscriber` (used by five controllers). Extracting only the Mailgun half would
leave the two diverged; the right change is one shared credential-test service for both,
which is a design decision, not a move.

---

## 5. Needs review — NOT in the work list

Each of these could be beneficial, but I am not 100% sure it is behaviour-neutral, or it
needs a decision only you can make.

### 5.1 Site-key verification cost (finding A) — **[IMPLEMENTED 2026-10-24 on your approval]**

**Measured:** `Hash::check` against the stored `$2y$12$` hash takes **~192 ms** on this
machine. It runs on **every** public request before `SiteCache` is consulted. An ISR
regeneration that fans out to 5–10 endpoints spends 1–2 s in bcrypt alone.

**Proposal:** memoize a *successful* verification in the cache:

```
key   = 'site-key-ok:' . $site->id . ':' . hash_hmac('sha256', $providedKey . '|' . $site->api_key, config('app.key'))
value = true, TTL ≈ 10 min
```

- The site is still looked up (slug + `active`) on every request, so **inactive → 404 stays instant**.
- Rotation changes `$site->api_key` (the stored hash), so the HMAC key changes and **an old key is rejected on the very next request**. No invalidation code is needed.
- Failed checks are never cached, so a wrong key always pays the full bcrypt (no oracle).
- 401/404/403 precedence and messages are unchanged.
- Cost of a hit: one Redis GET (~0.2 ms) instead of 192 ms.

**Why it's in "needs review":** it is the tenancy boundary (CLAUDE.md: *"Public endpoints
always go through VerifySiteAccess. No exceptions."*), and it adds a dependency on cache
availability (on a cache failure it must fall back to `Hash::check`, never deny or allow).
Rejected alternatives: lowering bcrypt cost or switching to SHA-256 would change the stored
hashes (a data change) and break the "stored as `Hash::make`" rule.

### 5.2 Job timeouts (finding F) — **[RESOLVED 2026-10-24: no risk today]**

`deploy/supervisor/casino-worker.conf` runs `queue:work` with no `--timeout`, so Laravel's default
60 s applies to these five jobs, well below `retry_after` (1200 s). No duplicate-send risk.
Declaring `public int $timeout = 60;` on each would be a pure no-op that satisfies the project rule
explicitly; optional.

_Original note:_

These jobs declare no `$timeout` and inherit the worker's `--timeout`:
`ProcessNewsletterSubscription`, `SendNewsletterWelcomeEmail`, `InvalidateCasinoCache`,
`SendVerificationPromotionJob`, `SendRegistrationPromotionJob`.
`retry_after` is 1200 s (redis). The worker flags live in `deploy/`, which is outside this
scope, so I can't confirm the effective value. Declaring an explicit timeout is a
behaviour-neutral no-op **only if** it equals the worker value. **Please tell me the
supervisor `--timeout`** and I'll add matching declarations.

### 5.3 Dead test scaffolding — **[DELETED 2026-10-24 on your decision]**

`app/Jobs/Test/ForHighPriorityJob.php`, `ForLowPriorityJob.php` and
`app/Console/Commands/TestCommand.php` (`test:command`, schedule line commented out in
`routes/console.php`). Removing them deletes an artisan command, which is technically an
observable change. Keep or delete?

### 5.4 Converting 85 `scopeX` methods to `#[Scope]`

Laravel 13 recommends `#[Scope] protected function x()`. Call sites (`->published()`) keep
working, but each conversion **renames a public method** (`scopePublished` → `published`)
and makes it protected, which you've ruled out. Kept as is; new scopes follow the existing
style.

### 5.5 Smaller items

| Item                                                                            | Concern                                                                                                                                            |
|---------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------|
| `declare(strict_types=1)` in `config/*.php` and the 6 framework-stub migrations | Config values come from `env()` strings. Strict types could turn silent coercion into a `TypeError` at boot. Migrations must not be edited anyway. |
| Observer registration → `#[ObservedBy]`                                         | The provider comment says registration **order** is deliberate (ForumPost before ForumArticle). Attributes would hide that ordering.               |
| `Model::automaticallyEagerLoadRelationships()`                                  | Same results, fewer queries, but changes memory and query patterns everywhere at once.                                                             |
| `Public\CasinoReviewController::feed` eager-limit rewrite                       | See §2.4. The original author's explicit decision.                                                                                                 |
| DB-4 bulk reorder                                                               | See §2.3. (DB-2 was rejected; see §2.2.)                                                                                                           |
| Mailgun/SMTP **job** dedup                                                      | Send-path semantics differ; needs a design discussion, not a refactor.                                                                             |

---

## 6. Behaviour inconsistencies observed — reported, **not** changed

These stood out while reading. Each one is "current behaviour" and is preserved by this
plan. They're listed so you can decide whether any is a bug.

1. **[FIXED 2026-10-24, listing only; the single-offer page stays ungated by design]** **`/special-offers` did not check `casinos.active`.** `Public\SpecialOfferController::baseQuery()` requires the casino to be attached and active **on the pivot**, but not `casinos.active = true`. The `/bonus` pages check both. An offer from a globally deactivated casino can therefore appear on `/special-offers` but not on `/bonus`.
2. **[FIXED 2026-10-24]** **Category page featured offer skipped the expiry gate.** `Public\CategoryController::show()` loads `featuredSpecialOffer` with `where('active', true)` only. `Public\CasinoController` uses `active + claimable()`, so an expired featured offer can show on a category page but not on the casino page.

---

## 7. Out of scope / follow-ups for you

- **Docs:** CLAUDE.md says to fix docs in the same change, but `../docs/` is outside the scope you set. DB-1 should be reflected in `docs/DATABASE.md`, and §5.1 (if approved) in `docs/SECURITY.md` / `docs/API.md`. I'll list the exact doc edits in each commit message so they can be applied separately.
- **Front ends:** no route, field or response shape changes are planned, so `admin/` and `sites/` need no changes.
- **Migration on the dev DB:** applying DB-1 (`php artisan migrate`) writes to your local MySQL. I'll ask before running it.

---

## 8. Proposed execution order

| Step | Module                                  | Risk     | Commit                                               |
|------|-----------------------------------------|----------|------------------------------------------------------|
| 0    | Characterization tests + factories (§3) | —        | `test: lock current behaviour of …`                  |
| 1    | Bootstrap & providers (§4.1)            | Low      | `refactor(bootstrap): …`                             |
| 2    | DB-1 index migration (§2.2)             | Low      | `perf(db): index casino_reviews for the public feed` |
| 3    | Shared scopes + DB-3 (§4.2, §4.3)       | Low      | `refactor(queries): …`                               |
| 4    | Form Requests, batches 1–5 (§4.4)       | Medium   | `refactor(validation): …` ×5                         |
| 5    | Receiver-campaign concern (§4.5)        | Medium   | `refactor(messaging): …`                             |
| 6    | §5.1 site-key memoization               | High     | Only with explicit sign-off                          |
| 7    | §4.6 service extraction                 | Med/High | Only with explicit sign-off                          |

**Please confirm:** (1) approve steps 0–5, (2) yes/no on §5.1, (3) the worker `--timeout`
for §5.2, (4) test scaffolding (§5.3): decided, deleted, (5) DB-2 drop: decided, kept.

---

## 9. Execution log (2026-10-24)

| Step               | Result                                                                                                                                                               |
|--------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1 Bootstrap        | Lazy-loading violations logged outside production. A full-suite run logged **zero** violations.                                                                      |
| 2 DB-1             | `casino_reviews_site_status_casino_pub_idx` migration; up/down verified on SQLite. Applied to the local MySQL (batch 93; confirmed in §10).                          |
| 3 Scopes           | 10 inline copies → `Casino::attachedToSite`, `SpecialOffer::casinoAttachedToSite`, `SpecialOffer::visibleInBonusArea`. SQL of 6,295 captured queries byte-identical. |
| 3b DB-3            | Duplicate soft-delete predicate removed from 2 Eloquent builders; exactly 5 of 2,404 captured queries changed, each by that predicate only.                          |
| 4 Form Requests    | 33 inline write validations → 25 Form Requests. Excluded where a 404/503 precedes validation (§4.4). GET filters left inline.                                        |
| 5 Receiver concern | 5 endpoints × 2 channels share one concern; 16 responses byte-identical before/after.                                                                                |
| 7 Services | `WarmupRunService`, `Sms\BulkSmsRunService`, `PostVerificationPromotionTestSender`, `VerificationPromotionOverrideService`, `Forum\ForumModerationService`, `Forum\ForumAccountService`. Shared `QueuedRunOutcome`/`QueuedRunRefusal` + `RespondsWithQueuedRun` for the two lock-guarded runs. Controllers: Warmup 781→391, NewsletterPhone 490→441, VerificationPromotion 485→260, ForumModeration 493→367, ForumAuth 476→289 lines. |

**Safety checks after every step:** `php -l` gate clean, full suite green (1,199 → 1,275 tests; 1,325 after step 7),
and the route table identical to `main` (344 routes: same methods, URIs, names, actions, middleware).

**Step 7 characterization tests (33):** WarmupEndpointContract, BulkSmsSendEndpoint,
VerificationPromotionAdminContract, ForumModerationActionsContract, ForumPasswordAuthContract;
each committed and run green against the unchanged controller first.

**New characterization tests (76):** AffiliateClick, CasinoFacets, NavItemReorder, SeoTemplate,
AdminReviewModeration, MailgunReceiverBulk, CasinoCountryDetachAll, ForumModerationValidation,
UniOneAdminWrites, ReceiverCampaignEndpoints.

### Bugs found while characterizing (reported, NOT fixed: behaviour freeze)

1. **[FIXED 2026-10-24]** **UniOne key rotation kept the old verification** (`UniOneKeyController::update`). The
   reset of `last_verified_at` / `last_verify_status` goes through `fill()`, but neither column is
   fillable, so it is silently discarded. A replaced, unproven key stays "verified" and eligible as
   the default sender.    Fixed with `forceFill()` on those two columns only; `is_default` is untouched, so a rotated
   default keeps sending (guarded by `test_rotating_the_default_key_keeps_it_the_sending_default`).
   `Model::preventSilentlyDiscardingAttributes()` in dev would catch this whole class of bug.
2. **[FIXED 2026-10-24]** **SEO template preview 500'd without `name`** (`SeoTemplateController::preview`). `validated()`
   drops an absent nullable key, then `$validated['name']` is read directly. Fixed with `($validated['name'] ?? null)`; an omitted name now falls back to "Example Casino" like a blank one.

### Tooling note

There is no `pint.json`, so Pint's default Laravel preset restyles each touched file
(unspaced concatenation, un-aligned `=>`). The codebase's own style is spaced concatenation
(315 vs 43 occurrences). The changes are whitespace-only (`git diff -w` hides them), but they
make diffs noisier. A `pint.json` with `concat_space: one` and `binary_operator_spaces: align`
would stop the churn; that's a config change for you to decide.

---

## 10. Second audit pass (2026-10-24, uncommitted)

An independent re-audit run after §9, to catch what the first pass missed. Same behaviour
freeze, same scope (`backend/` only). Nothing here is committed: version control is left to you.

### 10.1 Verified state before touching anything

| Check                          | Result                                                                                                  |
|--------------------------------|---------------------------------------------------------------------------------------------------------|
| Plan log vs. git history       | Matches: every §9 step has its commit on `refactor/backend-plan`.                                       |
| DB-1 on local MySQL            | **Already applied** (`migrate:status`: batch 93). §9 said "not yet applied"; corrected above.            |
| Baseline suite                 | 1,325 tests / 4,546 assertions, green.                                                                   |
| Suite → `laravel.log`?         | **No.** `phpunit.xml` sets `LOG_CHANNEL=null`; the log is unchanged since 2026-10-23 despite every run. |
| Lazy loads hit by the suite    | 0 (`LOG_CHANNEL=stderr vendor/bin/phpunit \| grep 'Lazy loaded'`).                                      |
| Inline write validation left   | None outside the documented 404-before-422 exclusions. The other 9 calls are GET listings or exports.   |

### 10.2 Database re-audit: real query evidence, no new index needed

§2 relied on static reading plus EXPLAIN. This pass also used MySQL's own statement digests
(`performance_schema.events_statements_summary_by_digest`, server uptime ≈ 138 days), which
record what the local admin and sites have **actually** run. (The Boost MCP server was not
connected in this session, so I used read-only `mysql` queries.)

| Query (from digests)                                                                                            | Finding                                                                                                                                                                                                                                                                                    |
|-----------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `promotion_email_histories … site_id=? AND status=? AND sent_date IN (…) AND created_at>? AND email IN (…)` (dedupe, 3,324 runs) | Index range scan on `promo_hist_site_date`, partition-pruned by `sent_date`. At volume `promo_hist_unique (site_id, email, sent_date, status)` serves the `email IN` lookup directly. **Covered.**                                                                                           |
| Recipient streaming: `newsletters WHERE site_id=? AND NOT EXISTS (unsubscribes …) ORDER BY created_at DESC, id DESC LIMIT 2000` | Reverse index scan on `newsletters_site_id_created_at_index` (InnoDB appends `id`, so the tiebreak is free) plus a **single-row covering lookup** on `unsubscribes_site_id_email_type_unique`. **Optimal.**                                                                                 |
| Date-ranged audience counts                                                                                      | Same anti-join, index-driven. A `COUNT` must visit every matching row; no index can avoid that.                                                                                                                                                                                             |
| Catalog joins (`casino_category`, `casino_country`, `casino_site`) flagged "no index used"                       | Planner choice on **7 casinos**: a scan is cheaper than a lookup at this size. Both pivot directions are indexed (`PRIMARY (category_id, casino_id)` + `casino_id`; same for countries; `casino_site (site_id, active, position)` + unique `(casino_id, site_id)`).                     |
| `*_id` columns not leading any index (8)                                                                         | All covered or never filtered on alone: morph pairs (`media`, `personal_access_tokens`, `search_index`, `forum_moderation_logs`), `forum_users.google_id` (in the unique `(site_id, google_id)`), `affiliate_clicks.special_offer_id` (in the daily unique), `unione_api_keys.project_id` and `receiver_daily_claims.credential_id` (never in a WHERE). |
| `sys.schema_redundant_indexes`                                                                                   | One row: `casino_reviews_site_id_index`, which is DB-2, kept on purpose (§2.2).                                                                                                                                                                                                             |

The `*_probe` digests (`nl_probe`, `w_probe`, …) are earlier load-test artefacts, not app queries.

**Verdict: no index to add or drop.** Earlier tuning passes already cover every hot path. §2.5
(production `sys` checks on a MySQL 8 snapshot) is still the right next step.

### 10.3 Implemented in this pass (low risk; suite 1,325 → 1,328, all green)

| Item                                                                                         | Files                                                                            | Why behaviour is unchanged                                                                                                                                                                                                                                                                                                                                                                       |
|----------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Discarded-attribute detection**, outside production only: `preventSilentlyDiscardingAttributes()` + a handler that logs | `app/Providers/AppServiceProvider.php`                                           | Read from the installed `Model::fill()` (13.38): with a handler registered, Eloquent calls it **instead of** throwing, including for **totally guarded** models. The handler therefore **rethrows** the same `MassAssignmentException` when `$model->totallyGuarded()`, so that path is identical; otherwise it only logs, and the key is still dropped exactly as before. Nothing else in the framework reads the flag. Production registers nothing. |
| Tests pinning the guard                                                                     | `tests/Feature/DiscardedAttributeGuardTest.php` (3 tests)                        | Dropped key stays dropped and is logged; a totally guarded model still throws (this test fails if the rethrow is removed); a clean payload logs nothing.                                                                                                                                                                                                                                       |
| `declare(strict_types=1)`                                                                    | `routes/web.php`, `routes/console.php`                                          | Every call in both files already passes correctly typed scalars. `schedule:list` shows the same 11 entries and `route:list` the same 344 routes after the change.                                                                                                                                                                                                                               |
| Stranded comment moved                                                                       | `routes/console.php`                                                             | "Provision upcoming monthly partitions…" sat above the Mailgun block; it now sits on `promotions:manage-history-partitions`. Comment only.                                                                                                                                                                                                                                                      |
| Doc note                                                                                     | `CLAUDE.md` (backend)                                                            | Documents the new log line next to the lazy-loading one.                                                                                                                                                                                                                                                                                                                                         |

Gates run: `php -l` over `app routes database config tests` clean, full suite green, route
table unchanged (344), schedule unchanged.

### 10.4 What the new guard found on its first run

Full suite with `LOG_CHANNEL=stderr`:

| Model           | Dropped keys              | Source                                                   | Verdict                                                                                                                                                       |
|-----------------|---------------------------|----------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ForumReport`   | `reporter_ip`, `status`   | **Production:** `Public\ForumPostController::report()`   | **Bug: fixed, see 10.5 #1.**                                                                                                                                  |
| `Country` ×31, `Continent` ×17, `BonusCategory` ×8 | `slug`        | Test fixtures                                            | By design: slugs are generated from the name and never accepted (the admin Form Requests don't take one). The fixtures pass a slug that happens to equal the generated one. |
| `WarmupSend`    | `status`                  | Fixture in `DeleteWarmupEmailsCommandTest`               | The column doesn't exist on `warmup_sends`; the fixture key is dead. Harmless; could be deleted from the test.                                               |

### 10.5 Needs review: found in this pass, NOT changed (behaviour freeze)

1. **[FIXED 2026-10-24 on your request]** **Forum reports never stored the reporter IP, and a re-report never reopened.**
   `ForumPostController::report()` passes `reporter_ip` and `status` to `updateOrCreate()`, but
   `ForumReport::$fillable` has neither, so both are silently dropped. Effects: every report is
   stored with `reporter_ip = NULL`, so the abuse signal the column exists for is lost; and when the
   same reporter re-reports a post whose report was dismissed or actioned, the row stays closed
   instead of returning to `open` (on first insert the DB default `open` hides this). **Fix if
   wanted:** add both to `$fillable` (`reporter_ip` is already `$hidden`). That is a behaviour
   change, so it's yours to approve. Needs a test for both effects first.
   **Done:** both columns added to `ForumReport::$fillable`. Two tests in `ForumPublicApiTest`
   (`test_a_report_stores_the_reporter_ip_packed`, `test_reporting_again_after_a_dismissal_reopens_the_report`)
   were written first and failed against the old code for exactly these two reasons. Suite 1,330 green; the guard no longer
   reports `ForumReport`. Safe because the only mass-assignment writer is that endpoint, where both values are
   server-chosen; moderation updates reports via the query builder, which never consults `$fillable`.
   **Follow-up, also on your request:** a re-report now also clears `handled_by` / `handled_at`, so a reopened
   report no longer names the moderator who closed it. Both columns were added to `$fillable` too, or `fill()` would
   have dropped the nulls silently. `test_reporting_again_after_a_dismissal_reopens_the_report` failed first (old
   moderator id and timestamp kept), then passed. On a first report nothing changes: both columns are NULL anyway.
2. **All guest reports on one post collapse into one row.** Same method: the `updateOrCreate` key is
   `(forum_post_id, forum_user_id)`, and for guests `forum_user_id` is NULL, so the second guest's
   report overwrites the first one's reason and note. The comment only describes the member case.
   Intended or not is a product decision.
3. **`forum:rescore` comment vs. code.** The comment says "every ten minutes"; the code is
   `->hourly()`. I changed neither: one of them is wrong, and which is a product call.
4. **[DONE 2026-10-25 on your request]** **Job `$timeout` declarations (refines §5.2).** Four jobs declared none:
   `ProcessNewsletterSubscription`, `SendNewsletterWelcomeEmail`, `SendVerificationPromotionJob`,
   `SendRegistrationPromotionJob`. Declaring `$timeout = 60` matches production's worker default, but
   **it is not a no-op locally**: `composer dev` runs `queue:listen --timeout=0` (unbounded), and a
   job-level `$timeout` overrides the worker's. Recommended anyway, for the project rule, but
   flagged because it changes dev behaviour.
   **Done:** `public int $timeout = 60;` on all four, so every queued job now declares one. Checked first that
   60 s is enough: each job is one send; the address lock in the two promotion jobs is a non-blocking `get()` held
   for 180 s (`SEND_LOCK_SECONDS`), so a timed-out send can't outlive its lock; the SendGrid validation call is
   capped at 3 s. Pinned by `tests/Feature/JobTimeoutTest.php` against the **redis** `retry_after` (1200). The
   existing `ForumAccountMailTest` check reads `queue.default`, which is `sync` in tests, so it compares against its
   90 s fallback. Suite 1,331 green. Locally under `queue:listen --timeout=0`, these four jobs are now killed after
   60 s instead of running unbounded.
5. **Mailgun/SendGrid credential-test service: decided, keep as is.** Re-read both `test()` methods.
   They have diverged in substance: site resolution (picked vs. first active), a Mailgun-only
   connection-test branch, a Mailgun-only 422, and different log keys. A shared service would need a
   hook for each of those, which makes it a redesign rather than a move. Closes the open item in §4.6.

### 10.6 Remaining roadmap (all optional, in order of value)

| # | Item                                                                     | Risk   | Gate                                               |
|---|--------------------------------------------------------------------------|--------|----------------------------------------------------|
| 1 | ~~Decide 10.5 #1 (forum report fillable)~~ **done**                      | Low    | Approved and fixed 2026-10-24                      |
| 2 | §2.5 production `sys` checks on a MySQL 8 snapshot                       | None   | Read-only, needs prod access                       |
| 3 | ~~10.5 #4 job timeouts~~ **done**                                        | Low    | Approved and done 2026-10-25                       |
| 4 | `pint.json` matching house style (§9 tooling note)                       | None   | Your decision                                      |
| 5 | Drop the dead `status` key from the `DeleteWarmupEmailsCommandTest` fixture | None | Test-only tidy                                     |
