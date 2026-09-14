<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refuseRealServices();
    }

    /**
     * Abort the run unless the environment is genuinely isolated.
     *
     * A throw rather than an assertion: a misconfigured environment must stop
     * before a single test body executes, instead of failing one test while the
     * rest keep writing to a live database or handing mail to a live relay.
     *
     * phpunit.xml already points DB_CONNECTION/DB_DATABASE at in-memory SQLite
     * and MAIL_MAILER at `array`. This re-checks it at RUNTIME, because those are
     * non-forced `<env>` entries: anything that populates the real values first
     * (a stray .env.testing, an exported shell variable, a future phpunit
     * upgrade) would silently hand the suite the production connection.
     */
    private function refuseRealServices(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: expected in-memory SQLite, got [%s / %s]. '
                . 'Check phpunit.xml and that no .env.testing or exported DB_* variable is overriding it.',
                $connection,
                $database === '' ? '(empty)' : $database,
            ));
        }

        // MAIL_MAILER=array only changes the DEFAULT mailer. Code that names a
        // mailer explicitly still resolves that mailer's own config — and
        // SendWarmupBatchJob does exactly that with Mail::mailer('smtp'), which
        // would pick up the real MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD from
        // .env and deliver to live inboxes. Every configured transport is
        // therefore rewritten to `array` here.
        //
        // This does not weaken any test: Mail::fake() replaces the whole mail
        // manager, so transport identity assertions ($mail->mailer === 'smtp')
        // are unaffected, and a test that needs a specific transport registers
        // its own after setUp().
        foreach (array_keys((array) config('mail.mailers', [])) as $mailer) {
            config()->set("mail.mailers.{$mailer}.transport", 'array');
        }

        // Belt and braces: even if something reconstructs an SMTP transport, it
        // must have nowhere real to connect and no credentials to authenticate.
        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.mailers.smtp.port', 1025);
        config()->set('mail.mailers.smtp.username', null);
        config()->set('mail.mailers.smtp.password', null);

        // Provider credentials must never be the real ones either — a runtime
        // per-credential mailer (SendgridTransportProvider / MailgunTransportProvider)
        // builds its config from these.
        config()->set('services.sendgrid.key', 'test-sendgrid-key');
        config()->set('services.mailgun.secret', 'test-mailgun-key');

        // The address-validation key is a SEPARATE credential from the send key
        // above, and it was the one gap in this guard. With a real key present,
        // every subscribe test made a live, BILLABLE call to the validation API
        // and its result depended on what SendGrid thought of a made-up address.
        // That hid for as long as the key in .env was dead — it 401'd, the gate
        // failed open, and the tests passed for entirely the wrong reason.
        config()->set('services.sendgrid_validation.key', '');
        config()->set('services.sendgrid_validation.enabled', false);

        // And the general case, so the next credential nobody thought of cannot
        // repeat it: any HTTP request a test did not explicitly fake now throws
        // instead of reaching the internet. A test that means to exercise a
        // client calls Http::fake() and is unaffected.
        Http::preventStrayRequests();
    }
}
