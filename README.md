# Backend — Casino Platform API

Headless Laravel 13 API (PHP 8.3+, MySQL 8, Redis, Sanctum). Multi-tenant by site.

Serves the Vue admin SPA and every public Next.js site. Part of the
[casino-platform](../README.md) monorepo.

## Documentation

| Doc | Covers |
|-----|--------|
| [CLAUDE.md](CLAUDE.md) | working rules for this app — read first |
| [../docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md) | request flow, processes, layering |
| [../docs/DATABASE.md](../docs/DATABASE.md) | the full schema |
| [../docs/API.md](../docs/API.md) | every endpoint |
| [../docs/MESSAGING.md](../docs/MESSAGING.md) | email & SMS subsystem |
| [../docs/CACHING.md](../docs/CACHING.md) | `SiteCache` + revalidation |
| [../docs/SECURITY.md](../docs/SECURITY.md) | tenancy rules and secrets |

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
# configure DB_*, CACHE_STORE=redis, QUEUE_CONNECTION=redis, REVALIDATE_SECRET, MAIL_*
php artisan migrate --seed     # prints each site's API key ONCE — copy them
php artisan storage:link
```

Full instructions: [../docs/SETUP.md](../docs/SETUP.md).

## Running

```bash
php artisan serve                          # http://localhost:8000
php artisan queue:work --queue=high,low    # REQUIRED — nothing runs on the default queue
php artisan schedule:work                  # only when testing campaigns
php artisan pail                           # readable live log
```

Three processes matter in production: web, a queue worker on `high,low`, and cron running
`schedule:run`. Without the worker, imports, email, SMS and cache revalidation never
happen. Without cron, no scheduled campaign ever sends. See [../deploy/README.md](../deploy/README.md).

## Verification

There is no CI. Before calling a change done:

```bash
find app routes database config -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/pint --dirty
cd ../admin && npm run build       # catches shared types that drifted from a Resource
```

> Avoid `php artisan test` in normal work — it writes `testing.` noise into
> `storage/logs/laravel.log`.
>
> `migrate:fresh --seed` **rotates every site's API key**; afterwards every
> `sites/*/.env.local` needs the new key and every `pnpm dev` needs a restart.

## Key entry points

| Path | What |
|------|------|
| `routes/api.php` | the entire public + admin surface |
| `routes/console.php` | the three scheduled commands |
| `app/Http/Middleware/VerifySiteAccess.php` | multi-tenant authentication |
| `app/Support/SiteCache.php` | per-site read cache |
| `app/Services/Mail/` | template catalog, mailer factory, transport providers |
| `config/promotions.php`, `sms.php`, `warmup.php` | throughput tunables, with the constraints documented inline |
