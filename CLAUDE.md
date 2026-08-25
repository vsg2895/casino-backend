# Backend (Laravel API) — context for Claude Code

Headless Laravel 13 API on PHP 8.3+. Multi-tenant by site. Serves JSON to the Vue admin
(Sanctum) and to many Next.js public sites (each with its own hashed API key).

Root context: [../CLAUDE.md](../CLAUDE.md) · Schema: [../docs/DATABASE.md](../docs/DATABASE.md) ·
Endpoints: [../docs/API.md](../docs/API.md) · Email/SMS: [../docs/MESSAGING.md](../docs/MESSAGING.md)

---

## Endpoint layout

| Prefix | Middleware |
|--------|-----------|
| `/api/v1/admin/auth/login` | none |
| `/api/v1/admin/*` | `auth:sanctum` |
| `/api/v1/public/sites/{site:slug}/*` | `verify.site` |
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
if (! Hash::check($providedKey, $site->api_key))  abort(403, 'Invalid site key');

$request->merge(['_site' => $site]);
app()->instance('current_site', $site);
```

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
2. **Cache every public read** with `SiteCache::remember($site->id, $tags, $key, 3600, …)`,
   returning **resolved arrays** — never models or unresolved Resources. See
   [../docs/CACHING.md](../docs/CACHING.md) for why (`__PHP_Incomplete_Class` silently
   truncated the casino endpoint from 186 fields to 32).
3. **Invalidate in an Observer**, not in a controller. `CasinoObserver::saved()` flushes
   `SiteCache` per affected site and dispatches `RevalidateNextJsSites`.
4. **Use API Resources for every response.** Never return a model or an array literal.
5. **Use Form Requests for every write.** Never validate inline.
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
│   │   ├── Admin/     Site, Casino, CasinoSiteAttachment, Category, SpecialOffer,
│   │   │              CmsPage, SocialLink, Newsletter, NewsletterPhone, SmsTemplate,
│   │   │              TwilioConfig, SendgridKey, MailgunKey, EmailSchedule,
│   │   │              SiteEmailTemplate, SiteVerifyEmail, SitePromotionEmail,
│   │   │              VerificationPromotionEmail, WarmupEmail, PromotionEmailHistory,
│   │   │              Unsubscribe, EmailTemplateType, MediaUpload
│   │   ├── Public/    Casino, Category, SpecialOffer, CmsPage, SocialLink,
│   │   │              Newsletter, Unsubscribe, Verify
│   │   └── AuthController.php
│   ├── Requests/{Admin,Public}/
│   ├── Resources/
│   └── Middleware/VerifySiteAccess.php
├── Models/            Site, Casino, Category, SpecialOffer, CmsPage, SocialLink,
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
                       promotions:manage-history-partitions, casinos:sync-seo-meta, …
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

| Method | Path | Purpose |
|--------|------|---------|
| GET | `casinos/{casino}/sites` | current attachments |
| POST | `casinos/{casino}/sites/sync` | replace **all** attachments in one transaction — what the admin multi-select uses |
| POST | `casinos/{casino}/sites` | attach one with overrides |
| PATCH | `casinos/{casino}/sites/{site}` | update overrides |
| DELETE | `casinos/{casino}/sites/{site}` | detach |

Follow this shape for any future many-to-many entity.

---

## Route ordering

Literal segments that could pass for an id — `count`, `import`, `export`, `send`,
`recipients`, `history`, `templates` — **must be declared before** the `apiResource` they
share a prefix with, or `{casino}` swallows `count` as an id. The existing routes carry
comments saying so; keep them.

---

## Conventions

| Thing | Convention |
|-------|-----------|
| Model | singular PascalCase |
| Table | plural snake_case |
| Pivot | alphabetical singular pair (`casino_site`) |
| Controller | `{Resource}Controller`, `index/show/store/update/destroy` |
| Resource | `{Resource}Resource` / `{Resource}Collection` |
| Form Request | `Store{X}Request` / `Update{X}Request` |

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

## SEO obligations

Every publicly exposed casino/offer/page must carry `slug`, `meta_title`,
`meta_description`, image paths and `updated_at` — the Next.js layer builds
`generateMetadata()` and JSON-LD from them, and cannot invent what the API omits.
