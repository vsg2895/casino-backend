<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Casino;
use App\Models\CmsPage;
use App\Observers\CasinoObserver;
use App\Policies\CmsPagePolicy;
use App\Repositories\Contracts\CmsPageRepositoryInterface;
use App\Repositories\CmsPageRepository;
use App\Services\Mail\Transport\MailgunApiTransport;
use Illuminate\Support\Facades\Gate;
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

        Gate::policy(CmsPage::class, CmsPagePolicy::class);

        // Native SendGrid HTTP API transport (not the SMTP relay). Used by the
        // `sendgrid` mailer (config('mail.public_mailer')) so public verification
        // emails are sent via the SendGrid Web API with the API key directly.
        // Admin + promotion mail deliver over .env SMTP instead
        // (config('mail.admin_mailer')).
        Mail::extend('sendgrid', function (array $config) {
            return (new SendgridTransportFactory())->create(
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
