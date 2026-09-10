<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Casino;
use App\Models\CmsPage;
use App\Models\CasinoReview;
use App\Models\Category;
use App\Models\SpecialOffer;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Notifications\ResetPassword;
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

        // The reset link must land in the ADMIN SPA, not on an API route.
        // Laravel's default builds a URL against APP_URL, which here is the
        // headless API — following it would 404 and the reset would look broken
        // rather than merely misconfigured. FRONTEND_URL already names the panel.
        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            $base = rtrim((string) config('app.frontend_url'), '/');

            return $base . '/reset-password?' . http_build_query([
                'token' => $token,
                // Carried in the link because the reset form must submit the
                // address the token was issued for; the broker verifies the pair.
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
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
