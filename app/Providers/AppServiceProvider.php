<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Casino;
use App\Models\CmsPage;
use App\Observers\CasinoObserver;
use App\Policies\CmsPagePolicy;
use App\Repositories\Contracts\CmsPageRepositoryInterface;
use App\Repositories\CmsPageRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
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
        // `sendgrid` mailer so public subscription emails are sent via the
        // SendGrid Web API with the API key directly. Admin "send test" emails
        // deliver over .env SMTP instead (see config/mail.php test_mailer).
        Mail::extend('sendgrid', function (array $config) {
            return (new SendgridTransportFactory())->create(
                new Dsn('sendgrid+api', 'default', $config['key'] ?? config('services.sendgrid.key')),
            );
        });
    }
}
