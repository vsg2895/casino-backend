<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Casino;
use App\Models\CmsPage;
use App\Models\CasinoReview;
use App\Models\Category;
use App\Models\SpecialOffer;
use App\Models\ForumArticle;
use App\Models\ForumPost;
use App\Models\Site;
use App\Observers\ForumArticleObserver;
use App\Observers\ForumPostObserver;
use App\Observers\SiteForumFlagObserver;
use App\Observers\CasinoObserver;
use App\Observers\Search\CasinoReviewSearchObserver;
use App\Observers\Search\CasinoSearchObserver;
use App\Observers\Search\CategorySearchObserver;
use App\Observers\Search\CmsPageSearchObserver;
use App\Observers\Search\SpecialOfferSearchObserver;
use App\Policies\CmsPagePolicy;
use App\Repositories\Contracts\CmsPageRepositoryInterface;
use App\Repositories\CmsPageRepository;
use App\Services\Mail\Transport\MailgunApiTransport;
use App\Services\Mail\Transport\SendgridClickTrackingClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CmsPageRepositoryInterface::class, CmsPageRepository::class);
    }

    public function boot(): void
    {
        /*
         * Public newsletter subscribe limiter.
         *
         * ONE named limiter returning TWO limits, rather than two stacked
         * `throttle:` middlewares. Stacking them looks equivalent and is not:
         * both derive their cache key from the same route signature + IP, so
         * they share a counter and every request costs TWO hits — the measured
         * effect was a 5/minute limit that actually admitted three.
         *
         * The distinct `by()` keys here keep the two windows independent.
         *
         * Keyed on IP because the endpoint is unauthenticated, and throttled at
         * all because it now spends a paid SendGrid validation credit per new
         * address — `verify.site` does no rate limiting of its own.
         */
        /*
         * Forum posting.
         *
         * FOUR independent windows, and they are independent on purpose. A
         * single limiter keyed on one thing is trivially defeated: per-account
         * alone loses to a botnet registering accounts, per-IP alone loses to a
         * single member on a shared office address being silenced by a
         * colleague.
         *
         * The per-minute limits stop a burst; the per-hour limits stop a slow
         * grind that never trips a per-minute window. Each `by()` key is
         * distinct, because limiters sharing a key share a counter and every
         * request then costs two hits — the same bug already documented on the
         * subscribe limiter above, where a 5/minute limit actually admitted
         * three.
         */
        RateLimiter::for('forum-post', static function ($request): array {
            $memberId = $request->user()?->id ?? 'guest';
            $ip = $request->ip();

            return [
                Limit::perMinute(3)->by("forum-post-min:{$memberId}"),
                Limit::perHour(30)->by("forum-post-hour:{$memberId}"),
                // Wider than the per-account limits: a household or an office
                // behind one address is normal, a hundred posts an hour from it
                // is not.
                Limit::perMinute(10)->by("forum-post-ip-min:{$ip}"),
                Limit::perHour(100)->by("forum-post-ip-hour:{$ip}"),
            ];
        });

        /*
         * Registration, keyed on IP alone — there is no account yet.
         *
         * Tight, because a registration is what a spam operation needs before it
         * can do anything else, and a real person registers once.
         */
        RateLimiter::for('forum-register', static fn ($request): array => [
            Limit::perMinute(2)->by('forum-register-min:' . $request->ip()),
            Limit::perDay(10)->by('forum-register-day:' . $request->ip()),
        ]);

        /*
         * Reporting. Generous per account — a reader working through a spam
         * flood is doing us a favour — and capped per IP so the queue itself
         * cannot be flooded.
         */
        RateLimiter::for('forum-report', static function ($request): array {
            $who = $request->user()?->id ?? $request->ip();

            return [
                Limit::perMinute(10)->by("forum-report-min:{$who}"),
                Limit::perHour(60)->by("forum-report-hour:{$who}"),
            ];
        });

        RateLimiter::for('subscribe', static fn ($request): array => [
            Limit::perMinute(5)->by('subscribe-min:' . $request->ip()),
            Limit::perHour(20)->by('subscribe-hour:' . $request->ip()),
        ]);

        /*
         * Forum counters.
         *
         * Registered here rather than with an attribute on the model so the
         * ordering is explicit: ForumArticleObserver recomputes a category from
         * its articles, and ForumPostObserver has already refreshed the article
         * by the time it asks for that. See ForumCounters for why every write is
         * an atomic SQL expression.
         */
        ForumPost::observe(ForumPostObserver::class);
        ForumArticle::observe(ForumArticleObserver::class);

        // Keeps the /forum → /reviews redirect from shadowing the community
        // forum once a site enables it. See the redirect's migration.
        Site::observe(SiteForumFlagObserver::class);

        Casino::observe(CasinoObserver::class);

        // Search index sync. Separate observers from CasinoObserver on purpose:
        // that one owns cache invalidation and Next.js revalidation, this one
        // owns `search_index`. Merging them would couple two independent
        // concerns and make a search bug able to break page revalidation.
        //
        // NOTE: pivot writes (casino_site, casino_category) fire no model
        // events, so those are hooked explicitly in the two admin controllers
        // that perform them — see CasinoController and
        // CasinoSiteAttachmentController.
        Casino::observe(CasinoSearchObserver::class);
        SpecialOffer::observe(SpecialOfferSearchObserver::class);
        Category::observe(CategorySearchObserver::class);
        CasinoReview::observe(CasinoReviewSearchObserver::class);
        CmsPage::observe(CmsPageSearchObserver::class);

        Gate::policy(CmsPage::class, CmsPagePolicy::class);

        /*
         * ONE password policy, for every place a password is set: the reset
         * flow and the change-password screen both call Password::defaults().
         *
         * Until now defaults() was never configured, which means it was Laravel's
         * bare min(8) — an eight-character all-lowercase password was accepted on
         * the reset form. This is a super-admin account for six live domains.
         *
         * `uncompromised()` checks the password against the HaveIBeenPwned
         * corpus using k-anonymity: only the first five characters of the SHA-1
         * are sent, never the password. It is PRODUCTION-ONLY because it makes an
         * outbound HTTPS call — in tests that would be slow and flaky, and on a
         * dev box offline it would block work.
         */
        Password::defaults(static function (): Password {
            $rule = Password::min(12)->mixedCase()->numbers()->symbols();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });

        // The reset link must land in the ADMIN SPA, not on an API route.
        // Laravel's default builds a URL against APP_URL, which here is the
        // headless API — following it would 404 and the reset would look broken
        // rather than merely misconfigured. FRONTEND_URL already names the panel.
        //
        // Defined once and used BOTH by createUrlUsing (kept, so anything else
        // asking the notification for a URL still gets the right one) and by the
        // toMailUsing callback below, which builds its own message and would
        // otherwise have to repeat this.
        $resetUrl = static function (object $notifiable, string $token): string {
            $base = rtrim((string) config('app.frontend_url'), '/');

            return $base . '/reset-password?' . http_build_query([
                'token' => $token,
                // Carried in the link because the reset form must submit the
                // address the token was issued for; the broker verifies the pair.
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        };

        ResetPassword::createUrlUsing($resetUrl);

        /*
         * Send the reset over the .env SMTP credentials, explicitly.
         *
         * Laravel's ResetPassword notification has no mailer of its own, so it
         * uses `mail.default` — env('MAIL_MAILER', 'log'). On a box where that
         * is unset, every reset link is written to storage/logs and NEVER SENT:
         * no error, no bounce, and an admin locked out with no way back in.
         * Where it is 'sendgrid', the reset leaves over the Web API instead of
         * the SMTP credentials.
         *
         * Naming the mailer here makes admin account recovery independent of
         * whatever transport the public mail happens to be using.
         */
        ResetPassword::toMailUsing(static function (object $notifiable, string $token) use ($resetUrl): MailMessage {
            $minutes = config('auth.passwords.users.expire', 60);

            return (new MailMessage())
                ->mailer((string) config('mail.password_reset_mailer'))
                ->subject('Reset your ' . config('app.name') . ' password')
                ->line('You are receiving this email because a password reset was requested for your admin account.')
                ->action('Reset password', $resetUrl($notifiable, $token))
                ->line("This link expires in {$minutes} minutes.")
                ->line('If you did not request a reset, no action is needed — your password stays unchanged.');
        });

        // Native SendGrid HTTP API transport (not the SMTP relay). Used by the
        // `sendgrid` mailer (config('mail.public_mailer')) so public verification
        // emails are sent via the SendGrid Web API with the API key directly.
        // Admin + promotion mail deliver over .env SMTP instead
        // (config('mail.admin_mailer')).
        Mail::extend('sendgrid', function (array $config) {
            // The HTTP client is wrapped so a single message can switch SendGrid
            // click tracking off from its own headers — see
            // {@see SendgridClickTrackingClient}. The wrapper is a no-op for every
            // message that does not carry the marker, so the other streams on this
            // mailer are unaffected and keep following the account settings.
            return (new SendgridTransportFactory(
                client: new SendgridClickTrackingClient(HttpClient::create()),
            ))->create(
                new Dsn('sendgrid+api', 'default', $config['key'] ?? config('services.sendgrid.key')),
            );
        });

        // Native Mailgun HTTP API transport. Registered the same way as the
        // SendGrid one above, and used only by per-credential mailers built at
        // runtime by MailgunTransportProvider (mail.mailers.mailgun_key_{id}) —
        // so nothing about the existing SMTP or SendGrid paths changes.
        //
        // Hand-rolled on symfony/http-client rather than symfony/mailgun-mailer,
        // which is not installed; see MailgunApiTransport for why the MIME
        // endpoint is used.
        Mail::extend('mailgun', function (array $config) {
            return new MailgunApiTransport(
                HttpClient::create(),
                (string) ($config['domain'] ?? config('services.mailgun.domain', '')),
                (string) ($config['key'] ?? config('services.mailgun.secret', '')),
                (string) ($config['region'] ?? config('services.mailgun.region', MailgunApiTransport::REGION_US)),
            );
        });
    }
}
