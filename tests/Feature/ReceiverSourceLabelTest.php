<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\SmtpCredential;
use App\Services\MailgunReceiverSelector;
use App\Support\Mail\ReceiverSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Receiver sources — ours or somebody else's — and which of them a credential
 * is allowed to mail.
 *
 * The rule that matters most is the DEFAULT. Every credential that existed
 * before this feature has no sources chosen, and must keep mailing everyone — a
 * targeting control that silently narrows an audience to nothing on deploy
 * would be discovered as "the campaign sent to nobody".
 *
 * The second is the column default. {@see ReceiverSource} is a closed set on a
 * NOT NULL column, so there is no third "unlabelled" state: every address that
 * predates the field is `our_clients`, which is what the migration backfills.
 */
class ReceiverSourceLabelTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function receiver(string $email, ?ReceiverSource $source = null): MailgunReceiver
    {
        return MailgunReceiver::create(array_filter([
            'email'        => $email,
            'source'       => MailgunReceiver::SOURCE_IMPORT,
            // Omitted entirely when null, so the column default applies — the
            // same path every pre-existing address takes.
            'source_label' => $source,
        ]));
    }

    /** @param  list<string>|null  $sources */
    private function credential(?array $sources): MailgunKey
    {
        return MailgunKey::create([
            'name'             => 'Key ' . uniqid(),
            'domain'           => 'mail.example.com',
            'api_key'          => 'key-test',
            'from_address'     => 'news@mail.example.com',
            'from_name'        => 'Test',
            'message_subject'  => 'Hello',
            'message_html'     => '<p>Hi</p>',
            'batch_size'       => 100,
            'selection_order'  => MailgunReceiver::ORDER_OLDEST,
            'status'           => 'active',
            'receiver_sources' => $sources,
        ]);
    }

    /** @param  list<string>|null  $sources  @return list<string> emails the credential would mail */
    private function audience(?array $sources): array
    {
        $emails = app(MailgunReceiverSelector::class)
            ->selection($this->credential($sources))
            ->orderBy('email')
            ->pluck('email')
            ->all();

        return $emails;
    }

    private function seedBothSources(): void
    {
        $this->receiver('ours@example.com', ReceiverSource::OurClients);
        $this->receiver('external@example.com', ReceiverSource::ExternalClients);
        // No source given: the column default files it with ours.
        $this->receiver('legacy@example.com');
    }

    // ── the default ──────────────────────────────────────────────────────────

    public function test_a_credential_with_no_sources_chosen_mails_everyone(): void
    {
        $this->seedBothSources();

        $this->assertSame(
            ['external@example.com', 'legacy@example.com', 'ours@example.com'],
            $this->audience(null),
        );
        $this->assertCount(3, $this->audience([]), 'an empty list means both, never neither');
    }

    public function test_choosing_both_is_the_same_as_choosing_neither(): void
    {
        $this->seedBothSources();

        $this->assertCount(3, $this->audience(ReceiverSource::values()));
    }

    // ── targeting ────────────────────────────────────────────────────────────

    public function test_our_clients_excludes_the_external_list(): void
    {
        $this->seedBothSources();

        // Two: the one filed explicitly and the one that took the default.
        $this->assertSame(
            ['legacy@example.com', 'ours@example.com'],
            $this->audience([ReceiverSource::OurClients->value]),
        );
    }

    public function test_external_clients_excludes_ours(): void
    {
        $this->seedBothSources();

        $this->assertSame(['external@example.com'], $this->audience([ReceiverSource::ExternalClients->value]));
    }

    public function test_everything_that_predates_the_field_counts_as_ours(): void
    {
        /*
         * The migration adds the column NOT NULL with `our_clients` as its
         * default, so every address already on the list is backfilled by the
         * ADD COLUMN itself. This pins the model half of that: a receiver
         * created without a source is ours.
         */
        $receiver = $this->receiver('legacy@example.com');

        $this->assertSame(ReceiverSource::OurClients, $receiver->fresh()->source_label);
    }

    // ── bad input ────────────────────────────────────────────────────────────

    public function test_an_unknown_value_is_dropped_rather_than_matched(): void
    {
        // A stale admin bundle posting a removed option must not mail nobody.
        $this->seedBothSources();

        $this->assertCount(3, $this->audience(['facebook_ads']));
        $this->assertCount(3, $this->audience(['', '   ']));
    }

    public function test_a_column_value_outside_the_enum_cannot_be_saved(): void
    {
        // The database is the last line: `enum` rejects anything else outright,
        // so no layer that forgets to validate can file an address under a
        // source no credential will ever target.
        $this->expectException(\Illuminate\Database\QueryException::class);

        MailgunReceiver::query()->insert([
            'email'             => 'rogue@example.com',
            'source'            => MailgunReceiver::SOURCE_MANUAL,
            'source_label'      => 'facebook_ads',
            'unsubscribe_token' => MailgunReceiver::newToken(),
        ]);
    }

    // ── both channels ────────────────────────────────────────────────────────

    public function test_the_smtp_channel_targets_the_same_way(): void
    {
        $this->seedBothSources();

        $credential = SmtpCredential::create([
            'name'             => 'Own SMTP',
            'type'             => 'smtp',
            'host'             => 'smtp.example.com',
            'port'             => 587,
            'username'         => 'mailer@example.com',
            'password'         => 'secret',
            'encryption'       => 'tls',
            'from_address'     => 'mailer@example.com',
            'from_name'        => 'Test',
            'message_subject'  => 'Hello',
            'message_html'     => '<p>Hi</p>',
            'batch_size'       => 100,
            'selection_order'  => MailgunReceiver::ORDER_OLDEST,
            'only_active'      => false,
            'status'           => 'active',
            'receiver_sources' => [ReceiverSource::ExternalClients->value],
        ]);

        $emails = app(MailgunReceiverSelector::class)
            ->selection($credential)
            ->pluck('email')
            ->all();

        $this->assertSame(['external@example.com'], $emails);
    }

    // ── the import, which is where a source is chosen ────────────────────────

    /** @param  list<array<int, string>>  $rows */
    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rcv') . '.csv';
        $this->tempFiles[] = $path;

        $writer = new CsvWriter();
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new UploadedFile($path, 'receivers.csv', null, null, true);
    }

    public function test_an_import_stamps_its_source_on_every_address(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/v1/admin/mailgun-receivers/import', [
            'file'         => $this->csv([['Email'], ['a@example.com'], ['b@example.com']]),
            'source_label' => ReceiverSource::ExternalClients->value,
        ], ['Accept' => 'application/json'])->assertAccepted();

        // The queue is sync under test, so the job has already run.
        foreach (['a@example.com', 'b@example.com'] as $email) {
            $this->assertSame(
                ReceiverSource::ExternalClients,
                MailgunReceiver::query()->where('email', $email)->value('source_label'),
            );
        }
    }

    public function test_an_import_that_says_nothing_files_the_rows_as_ours(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/v1/admin/mailgun-receivers/import', [
            'file' => $this->csv([['Email'], ['c@example.com']]),
        ], ['Accept' => 'application/json'])->assertAccepted();

        $this->assertSame(
            ReceiverSource::OurClients,
            MailgunReceiver::query()->where('email', 'c@example.com')->value('source_label'),
        );
    }

    public function test_an_import_refuses_a_source_outside_the_enum(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/v1/admin/mailgun-receivers/import', [
            'file'         => $this->csv([['Email'], ['d@example.com']]),
            'source_label' => 'facebook_ads',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_label');
    }

    public function test_the_sources_endpoint_lists_both_with_counts(): void
    {
        // What both pickers are built from: the Receivers filter and the
        // credential's "Send to sources" control.
        $this->actingAsAdmin();
        $this->seedBothSources();

        $this->getJson('/api/v1/admin/mailgun-receivers/sources')
            ->assertOk()
            ->assertJsonPath('data.sources.0.value', ReceiverSource::OurClients->value)
            ->assertJsonPath('data.sources.0.label', 'Our clients')
            ->assertJsonPath('data.sources.0.total', 2)
            ->assertJsonPath('data.sources.1.value', ReceiverSource::ExternalClients->value)
            ->assertJsonPath('data.sources.1.total', 1);
    }

    // ── the count the admin sees ─────────────────────────────────────────────

    public function test_the_preview_count_respects_the_chosen_sources(): void
    {
        /*
         * The modal's "eligible" number comes from the same selection as the
         * send. If it did not, an operator would preview one audience and mail
         * another — the exact drift the selector exists to prevent.
         */
        $this->seedBothSources();

        $selector = app(MailgunReceiverSelector::class);

        $this->assertSame(3, $selector->eligible($this->credential(null)));
        $this->assertSame(1, $selector->eligible($this->credential([ReceiverSource::ExternalClients->value])));
    }
}
