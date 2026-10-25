# Backend (Laravel API) — context for Claude Code

Headless Laravel 13 API on PHP 8.3+. Multi-tenant by site. Serves JSON to the Vue admin
(Sanctum) and to many Next.js public sites (each with its own hashed API key).

Root context: [../CLAUDE.md](../CLAUDE.md) · Schema: [../docs/DATABASE.md](../docs/DATABASE.md) ·
Endpoints: [../docs/API.md](../docs/API.md) · Email/SMS: [../docs/MESSAGING.md](../docs/MESSAGING.md)

---

## Endpoint layout

| Prefix                                                  | Middleware                                           |
|---------------------------------------------------------|------------------------------------------------------|
| `/api/v1/admin/auth/login`                              | none                                                 |
| `/api/v1/admin/*`                                       | `auth:sanctum`                                       |
| `/api/v1/public/sites/{site:slug}/*`                    | `verify.site`                                        |
| `/api/v1/verify/{token}`, `/api/v1/unsubscribe/{token}` | `throttle:60,1` only — the token *is* the credential |

Full route list: [../docs/API.md](../docs/API.md). Source of truth: `routes/api.php`.

---

## `VerifySiteAccess` — the foundation of tenancy

`app/Http/Middleware/VerifySiteAccess.php`, registered as `verify.site` and applied to
the entire public group.

```php
$slug = $request->route('site');
$providedKey = $request->header('X-Site-Key');

if (! $slug || ! $providedKey)        abort(401, 'Missing site credentials');

$site = Site::where('slug', $slug)->where('active', true)->first();
if (! $site)                          abort(404, 'Site not found');   // inactive == unknown, on purpose
if (! $this->keyMatches($site, $providedKey))      abort(403, 'Invalid site key');

$request->merge(['_site' => $site]);
app()->instance('current_site', $site);
```

`keyMatches()` is `Hash::check` with **successful** results remembered for 10 minutes. The
stored key is bcrypt cost 12, ~190 ms per check on every public request, and a site's
server sends the same key every time. The cache entry is
`site-key-ok:{site_id}:HMAC-SHA256(provided key | stored hash, APP_KEY)`, and that shape
is what keeps it safe. Do not simplify it:

- **Rotation is immediate.** The stored hash is part of the entry name, so after
  `rotateApiKey()` the old key maps to an entry that does not exist. No invalidation code.
- **Only matches are cached.** A wrong key pays full bcrypt every time.
- **The site row is still read on every request**, so deactivation is immediate (404).
- The plain key never reaches the cache, and a cache failure falls back to `Hash::check`
  (it can neither let a request in nor keep one out).

`VerifySiteAccessTest` pins all of this, including a check that bcrypt runs once.

Controllers read the resolved site from `$request->_site` or `app('current_site')` and
**scope every query with it** — through the `casino_site` pivot for casinos, through
`site_id` for owned rows.

## `Site` model

```php
protected $hidden = ['api_key'];   // NEVER in any API response

public static function generateApiKey(): string { return Str::random(64); }

public function rotateApiKey(): string
{
    $plain = self::generateApiKey();
    $this->update(['api_key' => Hash::make($plain)]);
    return $plain;                  // returned ONCE, for the admin to copy
}
```

`getSlugOptions()` uses `doNotGenerateSlugsOnUpdate()` — **the slug must never change**,
it is part of the public API path and every cache tag.

`frontendBaseUrl()` resolves `config('urls.sites.{slug}')` (`SITE_URL_<SLUG>`) and falls
back to `https://{domain}`. It builds the links baked into delivered email, so it is
**always the live https URL, never localhost.**

`emailTemplateOrDefault()` / `verifyEmailOrDefault()` / `promotionEmailOrDefault()`
`firstOrCreate` from `defaultsFor($site)`, so every site always has a template.

---

## Key principles

1. **Scope every public query by the resolved site.** No exceptions, no "internal" routes.
   Use the shared scopes rather than re-typing the predicate: `Casino::attachedToSite($id)`
   (pivot attached + active; deliberately NOT `casinos.active`, so add that where the surface
   needs it), `SpecialOffer::casinoAttachedToSite($id)`, and
   `SpecialOffer::visibleInBonusArea($id)` (offer active + claimable + casino active and
   attached). Raw `DB::table()` builders apply no global scopes, so they must keep their
   explicit `whereNull('…deleted_at')`.
2. **Cache every public read** with `SiteCache::remember($site->id, $tags, $key, 3600, …)`,
   returning **resolved arrays** — never models or unresolved Resources. See
   [../docs/CACHING.md](../docs/CACHING.md) for why (`__PHP_Incomplete_Class` silently
   truncated the casino endpoint from 186 fields to 32).
3. **Invalidate in an Observer**, not in a controller. `CasinoObserver::saved()` flushes
   `SiteCache` per affected site and dispatches `RevalidateNextJsSites`.
4. **Use API Resources for every response.** Never return a model or an array literal.
5. **Use Form Requests for every write.** Never validate inline. One exception: a method
   that can answer 404/403/503 **before** validating (an `assertOwns()`, a feature-flag
   `site()` helper that aborts) keeps its inline `validate()`, because a Form Request runs
   first and would turn that 404 into a 422 (see `ForumTaxonomyController`,
   `ForumAuthController`). Read-only GET filter validation may stay inline.
6. **No `__()` or lang files for content.** English only.
7. **Every job declares `public const string ON_QUEUE`** — `high` when a human is
   waiting (imports, welcome/verify mail), `low` for bulk fan-out (campaigns, SMS,
   warmup) — **and a timeout below the queue's `retry_after`.**

---

## Folder structure

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── Admin/     Site, Casino, CasinoSiteAttachment, CasinoReview, Category, Country,
│   │   │              SpecialOffer,
│   │   │              CmsPage, SocialLink, Newsletter, NewsletterPhone, SmsTemplate,
│   │   │              TwilioConfig, SendgridKey, MailgunKey, EmailSchedule,
│   │   │              SiteEmailTemplate, SiteVerifyEmail, SitePromotionEmail,
│   │   │              VerificationPromotionEmail, WarmupEmail, PromotionEmailHistory,
│   │   │              Unsubscribe, EmailTemplateType, MediaUpload
│   │   ├── Public/    Casino, CasinoReview, Category, Country, SpecialOffer, CmsPage, SocialLink,
│   │   │              Newsletter, Unsubscribe, Verify, SiteFeature
│   │   └── AuthController.php
│   ├── Controllers/Concerns/   ManagesReceiverCampaign (Mailgun + SMTP receiver endpoints),
│   │                          SendsAdminTestEmail, ResolvesTestSubscriber
│   ├── Requests/{Admin,Public,UniOne}/
│   ├── Resources/
│   └── Middleware/VerifySiteAccess.php
├── Models/            Site, Casino, CasinoReview, Category, Continent, Country, SpecialOffer,
│                      CmsPage, SocialLink,
│                      Newsletter, NewsletterBasedOnPhone, Unsubscribe, EmailSchedule,
│                      SendgridKey, MailgunKey, TwilioConfig, SmsTemplate, WarmupEmail, …
├── Services/          CmsPage, PromotionEmail, SubscriptionEmail, VerifyEmail,
│                      PostVerificationPromotionEmail, Revalidation, ScheduleRecipient,
│                      PhoneRecipient, WarmupRecipient, NewsletterImport, PhoneImport,
│                      Mail/ (Catalog, MailerFactory, Providers/, Transport/), Sms/
├── Repositories/      only where an interface earns its keep (CmsPageRepository)
├── Jobs/              Import*, Send*, InvalidateCasinoCache, RevalidateNextJsSites
├── Observers/         CasinoObserver
├── Policies/          CmsPagePolicy
├── Support/           SiteCache, CsvExport, EmailGreeting, LegalPageContent,
│                      Mail/, Phone/, Spreadsheet/
├── Mail/              Mailables + Concerns/HasSenderOverride, Contracts/SenderOverridable
└── Console/Commands/  promotions:dispatch-due, promotions:dispatch-verification,
                       promotions:manage-history-partitions, casinos:sync-seo-meta,
                       countries:fetch-flags, …
```

---

## Revalidation

`RevalidationService` POSTs each affected site's `revalidation_url` with
`x-revalidate-secret` and the tags. It runs from the queued `RevalidateNextJsSites` job,
uses a short timeout, and **swallows HTTP errors** — a site that is down must not fail an
admin save. That also means a misconfiguration looks like nothing happening; see
[../docs/TROUBLESHOOTING.md](../docs/TROUBLESHOOTING.md).

---

## Attachment pattern

For every entity that is many-to-many with sites, there is a dedicated attachment
controller (`CasinoSiteAttachmentController`):

| Method | Path                            | Purpose                                                                           |
|--------|---------------------------------|-----------------------------------------------------------------------------------|
| GET    | `casinos/{casino}/sites`        | current attachments                                                               |
| POST   | `casinos/{casino}/sites/sync`   | replace **all** attachments in one transaction — what the admin multi-select uses |
| POST   | `casinos/{casino}/sites`        | attach one with overrides                                                         |
| PATCH  | `casinos/{casino}/sites/{site}` | update overrides                                                                  |
| DELETE | `casinos/{casino}/sites/{site}` | detach                                                                            |

Follow this shape for any future many-to-many entity.

---

## Route ordering

Literal segments that could pass for an id — `count`, `import`, `export`, `send`,
`recipients`, `history`, `templates` — **must be declared before** the `apiResource` they
share a prefix with, or `{casino}` swallows `count` as an id. The existing routes carry
comments saying so; keep them.

---

## Conventions

| Thing        | Convention                                                |
|--------------|-----------------------------------------------------------|
| Model        | singular PascalCase                                       |
| Table        | plural snake_case                                         |
| Pivot        | alphabetical singular pair (`casino_site`)                |
| Controller   | `{Resource}Controller`, `index/show/store/update/destroy` |
| Resource     | `{Resource}Resource` / `{Resource}Collection`             |
| Form Request | `Store{X}Request` / `Update{X}Request`                    |

Config vs literal: a value that legitimately differs per environment is an `env()`; a
value whose change would corrupt behaviour (`warmup.mailer`,
`promotions.dedup_jitter_minutes`) is a **literal with a comment explaining why**.

## Packages

`laravel/sanctum`, `spatie/laravel-permission`, `spatie/laravel-medialibrary`,
`spatie/laravel-sluggable`, `intervention/image`, `openspout/openspout`,
`predis/predis`, `symfony/sendgrid-mailer`, `symfony/http-client`.

Do **not** add `spatie/laravel-translatable` or any i18n package.
Do **not** add `phpspreadsheet` — the running PHP version is unsupported by it;
OpenSpout is the replacement (`app/Support/Spreadsheet/`).

---

## Commands

```bash
php artisan serve
php artisan queue:work --queue=high,low   # required — nothing runs on the default queue
php artisan schedule:work                 # dev substitute for cron
php artisan migrate
php artisan countries:fetch-flags       # download country flags (idempotent)
php artisan tinker
php artisan pail                          # readable live log
vendor/bin/pint --dirty
```

**Verification gate** (no CI exists):

```bash
find app routes database config -name '*.php' -print0 | xargs -0 -n1 php -l
```

Avoid `php artisan test` in normal work — it fills `storage/logs/laravel.log` with
`testing.` noise. `migrate:fresh --seed` **rotates every site's API key**.

Outside production, lazy loads are **logged** as warnings, never thrown
(`AppServiceProvider`). To list N+1 queries the suite hits:
`LOG_CHANNEL=stderr vendor/bin/phpunit 2>&1 | grep 'Lazy loaded'` (empty today).

Keys that `fill()` drops because they are not in `$fillable` are logged the same way
(`Discarded non-fillable [...]`); the keys are still dropped, and a totally guarded model
still throws. Grep the same output for `Discarded non-fillable`. Today it reports only test
fixtures; its first production catch was `ForumReport` (`REFACTOR_PLAN.md` §10.5).

## SEO obligations

Every publicly exposed casino/offer/page must carry `slug`, `meta_title`,
`meta_description`, image paths and `updated_at` — the Next.js layer builds
`generateMetadata()` and JSON-LD from them, and cannot invent what the API omits.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== spatie/laravel-medialibrary/core rules ===

## Media Library

- `spatie/laravel-medialibrary` associates files with Eloquent models, with support for collections, conversions, and responsive images.
- Always activate the `medialibrary-development` skill when working with media uploads, conversions, collections, responsive images, or any code that uses the `HasMedia` interface or `InteractsWithMedia` trait.

</laravel-boost-guidelines>
