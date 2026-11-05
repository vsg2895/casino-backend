<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWarmupBatchJob;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use App\Services\Mail\WarmupCustomContent;
use App\Services\Mail\WarmupMailResolver;
use App\Services\Mail\WarmupRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The "Custom HTML" warmup template, its preview, and the one-time retry of a
 * temporary SMTP failure.
 *
 * No real email leaves this file: a local transport records every message it is
 * handed (and can be told to fail), so the full Mailer → Mailable → MIME path
 * runs while nothing reaches the network.
 */
class WarmupCustomTemplateTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string HTML = '<html><body><h1>Hello from the team</h1>'
        .'<p>This is a custom warmup message with real copy &amp; a <a href="https://example.com/a?b=1&amp;c=2">link</a>.</p>'
        .'</body></html>';

    /** @var list<Email> */
    public static array $delivered = [];

    /** Replies the transport throws, consumed one per send, before it starts accepting. */
    public static array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
        self::$delivered = [];
        self::$failures = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function useRecordingTransport(): void
    {
        Mail::extend('warmup-recording', fn (array $config): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                if (WarmupCustomTemplateTest::$failures !== []) {
                    throw new RuntimeException(array_shift(WarmupCustomTemplateTest::$failures));
                }

                $original = $message->getOriginalMessage();

                if ($original instanceof Email) {
                    WarmupCustomTemplateTest::$delivered[] = $original;
                }
            }

            public function __toString(): string
            {
                return 'warmup-recording://';
            }
        });

        config()->set('mail.mailers.warmup-recording', ['transport' => 'warmup-recording']);
        config()->set('warmup.mailer', 'warmup-recording');
        config()->set('mail.from.address', 'warm@example.com');
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload)
    {
        return $this->postJson('/api/v1/admin/warmup-emails/send', $payload);
    }

    public function test_the_dialog_offers_promotion_and_custom_but_no_longer_verify(): void
    {
        $this->actingAsAdmin();

        $values = collect($this->getJson('/api/v1/admin/warmup-emails/templates')->assertOk()->json('data'))
            ->pluck('value')
            ->all();

        $this->assertSame([EmailTemplateCatalog::TYPE_PROMOTION, WarmupMailResolver::TYPE_CUSTOM], $values);
    }

    public function test_the_verify_template_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        WarmupEmail::create(['email' => 'seed@example.com']);

        $this->send(['template' => EmailTemplateCatalog::TYPE_VERIFY])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template');
    }

    public function test_a_custom_send_requires_a_subject_and_html(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        WarmupEmail::create(['email' => 'seed@example.com']);

        $this->send(['template' => WarmupMailResolver::TYPE_CUSTOM])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_subject', 'custom_html']);
    }

    public function test_a_custom_send_delivers_the_html_as_typed_with_a_text_part(): void
    {
        $this->useRecordingTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug'), 'name' => 'Fsozbet']);
        WarmupEmail::create(['email' => 'seed@example.com']);

        $this->send([
            'template'       => WarmupMailResolver::TYPE_CUSTOM,
            'custom_subject' => 'A note from Fsozbet',
            'custom_html'    => self::HTML,
        ])->assertAccepted();

        // Stored once on the run, for every batch to read.
        $run = WarmupSend::query()->sole();
        $this->assertSame('A note from Fsozbet', $run->custom_subject);
        $this->assertSame(self::HTML, $run->custom_html);

        $this->assertCount(1, self::$delivered);
        $email = self::$delivered[0];

        $this->assertSame('A note from Fsozbet', $email->getSubject());
        $this->assertSame(self::HTML, $email->getHtmlBody(), 'the HTML is sent exactly as typed');
        $this->assertSame('Fsozbet', $email->getFrom()[0]->getName());
        $this->assertSame('warm@example.com', $email->getFrom()[0]->getAddress());

        $text = (string) $email->getTextBody();
        $this->assertStringContainsString('Hello from the team', $text);
        $this->assertStringContainsString('link (https://example.com/a?b=1&c=2)', $text, 'links keep their address, unescaped');
        $this->assertStringNotContainsString('<p>', $text);

        $this->assertSame(
            '<mailto:warm@example.com?subject=unsubscribe>',
            $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(),
        );

        $this->assertSame(
            WarmupSendRecipient::STATUS_SENT,
            WarmupSendRecipient::query()->sole()->status,
        );
        $this->assertSame('Custom HTML', $this->getJson('/api/v1/admin/warmup-emails/history')->json('data.0.template_label'));
        $this->assertSame($site->id, WarmupSendRecipient::query()->sole()->site_id);
    }

    public function test_the_preview_returns_the_html_the_text_part_and_warnings(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/warmup-emails/custom-preview', [
            'custom_subject' => 'Hi',
            'custom_html'    => '<p>Short</p><script>alert(1)</script><img src="http://x.test/a.png">',
        ])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Hi')
            ->assertJsonPath('data.html', '<p>Short</p><script>alert(1)</script><img src="http://x.test/a.png">')
            ->assertJsonPath('data.text', 'Short')
            ->assertJsonCount(4, 'data.warnings');

        // Nothing is stored or sent by looking.
        $this->assertSame(0, WarmupSend::query()->count());
    }

    public function test_a_clean_message_previews_without_warnings(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/warmup-emails/custom-preview', [
            'custom_subject' => 'Hi',
            'custom_html'    => self::HTML,
        ])
            ->assertOk()
            ->assertJsonPath('data.warnings', []);
    }

    public function test_an_oversized_html_body_is_rejected(): void
    {
        config()->set('warmup.custom.max_html_bytes', 100);
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/warmup-emails/custom-preview', [
            'custom_subject' => 'Hi',
            'custom_html'    => str_repeat('x', 101),
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_html');
    }

    public function test_a_custom_batch_without_its_content_sends_nothing(): void
    {
        $this->useRecordingTransport();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        WarmupEmail::create(['email' => 'seed@example.com']);
        $run = WarmupSend::create(['site_id' => $site->id, 'template' => WarmupMailResolver::TYPE_CUSTOM]);

        (new SendWarmupBatchJob(['seed@example.com'], $site->id, WarmupMailResolver::TYPE_CUSTOM, $run->id))
            ->handle(app(WarmupMailResolver::class), app(WarmupRateLimiter::class));

        $this->assertSame([], self::$delivered);
        $this->assertSame(0, WarmupSendRecipient::query()->count());
    }

    public function test_a_temporary_smtp_failure_is_retried_once(): void
    {
        $this->useRecordingTransport();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        WarmupEmail::create(['email' => 'seed@example.com']);
        $run = WarmupSend::create([
            'site_id'        => $site->id,
            'template'       => WarmupMailResolver::TYPE_CUSTOM,
            'custom_subject' => 'Hi',
            'custom_html'    => self::HTML,
        ]);
        self::$failures = ['Expected response code "250" but got code "451", with message "451 4.7.1 Try again later".'];

        (new SendWarmupBatchJob(['seed@example.com'], $site->id, WarmupMailResolver::TYPE_CUSTOM, $run->id))
            ->handle(app(WarmupMailResolver::class), app(WarmupRateLimiter::class));

        $this->assertCount(1, self::$delivered, 'the retry went out');
        $this->assertSame(WarmupSendRecipient::STATUS_SENT, WarmupSendRecipient::query()->sole()->status);
    }

    public function test_a_permanent_smtp_failure_is_not_retried(): void
    {
        $this->useRecordingTransport();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        WarmupEmail::create(['email' => 'seed@example.com']);
        $run = WarmupSend::create([
            'site_id'        => $site->id,
            'template'       => WarmupMailResolver::TYPE_CUSTOM,
            'custom_subject' => 'Hi',
            'custom_html'    => self::HTML,
        ]);
        // Two queued failures: a retry would consume the second one.
        self::$failures = ['550 5.1.1 mailbox unavailable', '550 5.1.1 mailbox unavailable'];

        (new SendWarmupBatchJob(['seed@example.com'], $site->id, WarmupMailResolver::TYPE_CUSTOM, $run->id))
            ->handle(app(WarmupMailResolver::class), app(WarmupRateLimiter::class));

        $this->assertSame([], self::$delivered);
        $this->assertCount(1, self::$failures, 'only one attempt was made');
        $this->assertSame(WarmupSendRecipient::STATUS_FAILED, WarmupSendRecipient::query()->sole()->status);
    }

    public function test_temporary_and_permanent_failures_are_told_apart(): void
    {
        foreach ([
            'Expected response code "250" but got code "421", with message "421 Too many connections".' => true,
            '452 4.2.2 Mailbox full, try later' => true,
            'Connection could not be established with host "smtp.example.com"' => true,
            '550 5.1.1 User unknown' => false,
            'Expected response code "250" but got code "554", with message "554 5.7.1 Rejected".' => false,
        ] as $message => $temporary) {
            $this->assertSame($temporary, SendWarmupBatchJob::isTemporaryFailure(new RuntimeException($message)), $message);
        }
    }

    public function test_the_plain_text_part_drops_styles_and_scripts(): void
    {
        $text = (new WarmupCustomContent('s', '<head><style>p{color:red}</style></head><p>One</p><p>Two<br>Three</p><script>x()</script>'))
            ->plainText();

        $this->assertSame("One\n\nTwo\nThree", $text);
    }
}
