<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use App\Models\MailgunKey;
use App\Models\SendgridKey;
use App\Services\Mail\Providers\MailgunTransportProvider;
use App\Services\Mail\Providers\PromotionTransportProvider;
use App\Services\Mail\Providers\SendgridTransportProvider;
use App\Services\Mail\Providers\SmtpTransportProvider;
use Illuminate\Contracts\Mail\Mailer;

/**
 * Resolves the mail transport for a promotion campaign from its saved provider.
 *
 * Previously this class held the SMTP and SendGrid branches inline. They now
 * live behind {@see PromotionTransportProvider}, one implementation per
 * provider, and this class is the registry that picks between them. The
 * behaviour of each existing branch is unchanged — SMTP still resolves to
 * config('mail.admin_mailer'), SendGrid still requires an ACTIVE stored key and
 * still throws the same {@see PromotionMailerException} otherwise.
 *
 * Adding a provider is one entry in {@see providers()} plus its implementation;
 * no caller changes.
 *
 * The From address is the same for every provider (config('mail.from.address')),
 * so switching transport never silently changes the visible sender.
 */
class PromotionMailerFactory
{
    /** @var array<string, PromotionTransportProvider>|null */
    private ?array $providers = null;

    /**
     * @param  int|null  $credentialId  Row id in the selected provider's
     *                                  credential table. Null for SMTP.
     *
     * @throws PromotionMailerException when the provider has no usable credential.
     */
    public function resolve(string $provider, ?int $credentialId): ResolvedPromotionMailer
    {
        return new ResolvedPromotionMailer(
            $this->providerFor($provider)->resolve($credentialId),
            config('mail.from.address') ?: null,
        );
    }

    /**
     * Mailer bound to a specific stored SendGrid key, active or not.
     *
     * Kept on this class as a stable entry point for the admin "Send test"
     * action, which predates the provider abstraction.
     *
     * @throws PromotionMailerException
     */
    public function mailerForKey(SendgridKey $key): Mailer
    {
        return $this->sendgrid()->mailerForKey($key);
    }

    /**
     * Mailer bound to a specific stored Mailgun credential, active or not —
     * the Mailgun counterpart of {@see mailerForKey()}.
     *
     * @throws PromotionMailerException
     */
    public function mailerForMailgunKey(MailgunKey $key): Mailer
    {
        return $this->mailgun()->mailerForKey($key);
    }

    /**
     * The provider for a stored value.
     *
     * An unknown provider falls back to SMTP rather than throwing: the column is
     * a free-form string, and a schedule saved by a future version must degrade
     * to the default transport instead of breaking the whole campaign.
     */
    private function providerFor(string $provider): PromotionTransportProvider
    {
        return $this->providers()[$provider]
            ?? $this->providers()[EmailSchedule::PROVIDER_SMTP];
    }

    /** @return array<string, PromotionTransportProvider> */
    private function providers(): array
    {
        if ($this->providers === null) {
            $this->providers = [];

            foreach ([new SmtpTransportProvider(), new SendgridTransportProvider(), new MailgunTransportProvider()] as $provider) {
                $this->providers[$provider->name()] = $provider;
            }
        }

        return $this->providers;
    }

    private function sendgrid(): SendgridTransportProvider
    {
        /** @var SendgridTransportProvider $provider */
        $provider = $this->providers()[EmailSchedule::PROVIDER_SENDGRID];

        return $provider;
    }

    private function mailgun(): MailgunTransportProvider
    {
        /** @var MailgunTransportProvider $provider */
        $provider = $this->providers()[EmailSchedule::PROVIDER_MAILGUN];

        return $provider;
    }
}
