<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\SmtpCredential;
use App\Services\MailgunReceiverSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Source labels: where an address came from, and which of those lists a
 * credential is allowed to mail.
 *
 * The rule that matters most is the DEFAULT. Every credential that existed
 * before this feature has no sources chosen, and must keep mailing the whole
 * list — a targeting control that silently narrows an audience to nothing on
 * deploy would be discovered as "the campaign sent to nobody".
 *
 * The second is the sentinel. Most of an established list carries no label at
 * all (it was imported before labelling existed), so naming any source has to
 * leave a way to say "and the unlabelled ones too", or those addresses become
 * unreachable.
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

    private function receiver(string $email, ?string $label): MailgunReceiver
    {
        return MailgunReceiver::create([
            'email'        => $email,
            'source'       => MailgunReceiver::SOURCE_IMPORT,
            'source_label' => $label,
        ]);
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

    private function seedThreeLists(): void
    {
        $this->receiver('fb@example.com', 'Facebook ads');
        $this->receiver('partner@example.com', 'Partner list 3');
        $this->receiver('legacy@example.com', null);
    }

    // ── the default ──────────────────────────────────────────────────────────

    public function test_a_credential_with_no_sources_chosen_mails_everyone(): void
    {
        $this->seedThreeLists();

        $this->assertSame(
            ['fb@example.com', 'legacy@example.com', 'partner@example.com'],
            $this->audience(null),
        );
        $this->assertCount(3, $this->audience([]), 'an empty list means all, never none');
    }

    // ── targeting ────────────────────────────────────────────────────────────

    public function test_choosing_one_source_mails_only_that_list(): void
    {
        $this->seedThreeLists();

        $this->assertSame(['fb@example.com'], $this->audience(['Facebook ads']));
    }

    public function test_several_sources_can_be_chosen_at_once(): void
    {
        $this->seedThreeLists();

        $this->assertSame(
            ['fb@example.com', 'partner@example.com'],
            $this->audience(['Facebook ads', 'Partner list 3']),
        );
    }

    public function test_the_sentinel_targets_the_unlabelled_addresses(): void
    {
        $this->seedThreeLists();

        $this->assertSame(['legacy@example.com'], $this->audience([MailgunReceiver::SOURCE_LABEL_NONE]));

        // And it composes with a real label, which is how an operator mails a
        // new list together with everything imported before labelling existed.
        $this->assertSame(
            ['fb@example.com', 'legacy@example.com'],
            $this->audience(['Facebook ads', MailgunReceiver::SOURCE_LABEL_NONE]),
        );
    }

    public function test_an_unknown_label_mails_nobody_rather_than_everybody(): void
    {
        // The dangerous failure would be treating "no matches" as "no filter".
        $this->seedThreeLists();

        $this->assertSame([], $this->audience(['A list that does not exist']));
    }

    // ── normalisation ────────────────────────────────────────────────────────

    public function test_a_label_is_trimmed_and_an_empty_one_becomes_null(): void
    {
        $padded = $this->receiver('padded@example.com', '  Facebook ads  ');
        $blank = $this->receiver('blank@example.com', '   ');

        $this->assertSame('Facebook ads', $padded->fresh()->source_label);
        $this->assertNull($blank->fresh()->source_label, '"" and NULL must not be two kinds of "no source"');

        // So a padded label is reachable by the clean one.
        $this->assertContains('padded@example.com', $this->audience(['Facebook ads']));
    }

    public function test_blank_entries_in_the_credential_list_are_ignored(): void
    {
        $this->seedThreeLists();

        // A control that posts [""] must not be read as "one source named
        // nothing", which would match no row and mail nobody.
        $this->assertCount(3, $this->audience(['', '   ']));
    }

    // ── both channels ────────────────────────────────────────────────────────

    public function test_the_smtp_channel_targets_the_same_way(): void
    {
        $this->seedThreeLists();

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
            'receiver_sources' => ['Partner list 3'],
        ]);

        $emails = app(MailgunReceiverSelector::class)
            ->selection($credential)
            ->pluck('email')
            ->all();

        $this->assertSame(['partner@example.com'], $emails);
    }

    // ── the import, which is where labels come from ──────────────────────────

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

    public function test_an_import_stamps_its_label_on_every_address(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/v1/admin/mailgun-receivers/import', [
            'file'         => $this->csv([['Email'], ['a@example.com'], ['b@example.com']]),
            'source_label' => '  Facebook ads  ',
        ], ['Accept' => 'application/json'])->assertAccepted();

        // The queue is sync under test, so the job has already run.
        $this->assertSame('Facebook ads', MailgunReceiver::query()->where('email', 'a@example.com')->value('source_label'));
        $this->assertSame('Facebook ads', MailgunReceiver::query()->where('email', 'b@example.com')->value('source_label'));
    }

    public function test_an_import_without_a_label_leaves_the_rows_unlabelled(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/v1/admin/mailgun-receivers/import', [
            'file' => $this->csv([['Email'], ['c@example.com']]),
        ], ['Accept' => 'application/json'])->assertAccepted();

        $this->assertNull(MailgunReceiver::query()->where('email', 'c@example.com')->value('source_label'));
    }

    public function test_the_sources_endpoint_lists_the_labels_in_use_with_counts(): void
    {
        // What both pickers are built from: the Receivers filter and the
        // credential's "Send to sources" control.
        $this->actingAsAdmin();
        $this->seedThreeLists();

        $this->getJson('/api/v1/admin/mailgun-receivers/sources')
            ->assertOk()
            ->assertJsonPath('data.sources.0.label', 'Facebook ads')
            ->assertJsonPath('data.sources.0.total', 1)
            ->assertJsonPath('data.sources.1.label', 'Partner list 3')
            ->assertJsonPath('data.unlabelled', 1)
            ->assertJsonPath('data.none_value', MailgunReceiver::SOURCE_LABEL_NONE);
    }

    // ── the count the admin sees ─────────────────────────────────────────────

    public function test_the_preview_count_respects_the_chosen_sources(): void
    {
        /*
         * The modal's "eligible" number comes from the same selection as the
         * send. If it did not, an operator would preview one audience and mail
         * another — the exact drift the selector exists to prevent.
         */
        $this->seedThreeLists();

        $selector = app(MailgunReceiverSelector::class);

        $this->assertSame(3, $selector->eligible($this->credential(null)));
        $this->assertSame(1, $selector->eligible($this->credential(['Facebook ads'])));
    }
}
