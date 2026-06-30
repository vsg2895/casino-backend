# Backend (Laravel API) — context for Claude Code

Headless Laravel 13 API. Multi-tenant by site. Serves JSON to:
- The Vue admin panel (Sanctum-authenticated)
- Many Next.js public sites (each authenticated by its own API key)

## Endpoint layout

- `/api/v1/auth/*` — admin authentication (Sanctum)
- `/api/v1/admin/*` — private admin endpoints (middleware `auth:sanctum`)
- `/api/v1/public/sites/{site:slug}/*` — public endpoints (middleware `verify.site`)

## Public API security — `VerifySiteAccess` middleware

This middleware is registered as `verify.site` and applied to the entire `/api/v1/public/sites/{site:slug}` group. It is the foundation of multi-tenant security.

```php
// app/Http/Middleware/VerifySiteAccess.php (skeleton)
public function handle(Request $request, Closure $next): Response
{
    $slug = $request->route('site');                  // from {site:slug}
    $providedKey = $request->header('X-Site-Key');

    if (!$slug || !$providedKey) {
        abort(401, 'Missing site credentials');
    }

    $site = Site::where('slug', $slug)->where('active', true)->first();
    if (!$site) {
        abort(404, 'Site not found');
    }

    if (!Hash::check($providedKey, $site->api_key)) {
        abort(403, 'Invalid site key');
    }

    // Optional: verify request origin matches registered domain
    // if (! $this->originMatches($request, $site->domain)) {
    //     abort(403, 'Origin mismatch');
    // }

    // Attach site to request so controllers can access it
    $request->merge(['_site' => $site]);
    app()->instance('current_site', $site);

    return $next($request);
}
```

Rate limit on the public group: `throttle:public-api` keyed by `X-Site-Key`.

## Site model — API key generation

```php
// app/Models/Site.php
class Site extends Model
{
    protected $hidden = ['api_key'];   // NEVER expose in any API response

    public static function generateApiKey(): string
    {
        return Str::random(64);        // 64-char URL-safe key
    }

    public function rotateApiKey(): string
    {
        $plain = self::generateApiKey();
        $this->update(['api_key' => Hash::make($plain)]);
        return $plain;                  // return plain ONCE for the admin to copy
    }
}
```

The admin endpoint for site creation returns the plain key **only in the create/rotate response payload**. After that, the plain key is unrecoverable from the DB.

## Key principles

1. **Every public endpoint controller** receives the resolved Site via `app('current_site')` or `$request->_site`. Use it to scope every query.
2. **Cache every public endpoint** via `Cache::tags(['site:'.$site->id])` with TTL ≥ 1 hour.
3. **On admin writes** that affect public content — call `RevalidationService::revalidate($tags, $affectedSiteIds)`.
4. **Use API Resources** — never return models directly. The Site Resource must never include `api_key`.
5. **Use Form Requests** for validation.
6. **Never use Laravel `__()` or lang files for content** — English only.

## Folder structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── Admin/
│   │   │   │   ├── SiteController.php           — register, list, rotate keys
│   │   │   │   ├── CasinoController.php
│   │   │   │   ├── CasinoSiteAttachmentController.php  — attach/detach + overrides
│   │   │   │   ├── BonusController.php
│   │   │   │   ├── BannerController.php
│   │   │   │   ├── ArticleController.php
│   │   │   │   └── AnalyticsController.php
│   │   │   └── Public/
│   │   │       ├── CasinoController.php
│   │   │       ├── BonusController.php
│   │   │       ├── BannerController.php
│   │   │       ├── ArticleController.php
│   │   │       └── TrackingController.php       — click logging
│   │   └── AuthController.php
│   ├── Requests/
│   ├── Resources/
│   └── Middleware/
│       └── VerifySiteAccess.php
├── Models/
├── Services/
│   ├── CacheService.php
│   └── RevalidationService.php
└── Jobs/
```

## RevalidationService

Pings every affected Next.js site so it knows to rebuild stale static pages.

```php
public function revalidate(array $tags, array $siteIds): void
{
    $sites = Site::whereIn('id', $siteIds)->where('active', true)->get();
    foreach ($sites as $site) {
        if (!$site->revalidation_url) continue;
        Http::withHeaders(['x-revalidate-secret' => env('REVALIDATE_SECRET')])
            ->timeout(5)
            ->post($site->revalidation_url, ['tags' => $tags]);
    }
}
```

`sites.revalidation_url` holds the `/api/revalidate` URL of the corresponding Next.js deployment.

## Attachment controllers (very important pattern)

For each many-to-many entity, there is a dedicated controller for managing attachments. Example for Casino:

- `POST /api/v1/admin/casinos/{casino}/sites` — attach casino to a site with override fields
- `PATCH /api/v1/admin/casinos/{casino}/sites/{site}` — update overrides (e.g. change affiliate URL)
- `DELETE /api/v1/admin/casinos/{casino}/sites/{site}` — detach
- `POST /api/v1/admin/casinos/{casino}/sites/sync` — replace all attachments at once (used by the admin UI's multi-select)

Same pattern for Bonus and Banner.

## Conventions

- **Models:** singular PascalCase
- **Tables:** plural snake_case
- **Controllers:** `{Resource}Controller`, methods `index/show/store/update/destroy`
- **API Resources:** `{Resource}Resource`, `{Resource}Collection`
- **Form Requests:** `Store{Resource}Request`, `Update{Resource}Request`

## Required packages

- `laravel/sanctum` — admin authentication
- `spatie/laravel-permission` — admin roles
- `spatie/laravel-medialibrary` — casino logos, banner images
- `spatie/laravel-sluggable` — SEO slugs
- `intervention/image` — image processing, WebP conversion

Do NOT add `spatie/laravel-translatable` or any i18n package.

## Commands

```bash
php artisan serve
php artisan migrate
php artisan migrate:fresh --seed
php artisan tinker
php artisan test
```

## Backend SEO obligations

Every casino/article record exposed publicly must include:
- `slug`, `meta_title`, `meta_description`, `og_image`, `schema_markup`, `updated_at`