<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSendgridReceiverCampaignJob;
use App\Mail\MailgunReceiverMessage;
use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\MailgunSuppression;
use App\Models\SendgridKey;
use App\Models\SendgridReceiver;
use App\Services\MailgunReceiverSelector;
use App\Services\ReceiverCampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Custom Receivers → Sendgrid Receivers: the second list, mailed by SendGrid
 * keys through the same selector and send loop as the Mailgun list.
 *
 * What matters is SEPARATION — every write lands in the SendGrid tables and
 * nothing on the Mailgun side moves — except for the one thing the lists share
 * on purpose: the suppression list.
 */
class SendgridReceiverListTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function key(array $attrs = []): SendgridKey
    {
        return SendgridKey::create([
            'name'            => 'SendGrid',
            'api_key'         => 'SG.test-key-value-long-enough',
            'from_address'    => 'offers@example.com',
            'from_name'       => 'Offers',
            'message_subject' => 'Hello',
            'message_html'    => '<p>Hi</p>',
            'batch_size'      => 100,
            'selection_order' => SendgridReceiver::ORDER_OLDEST,
            'status'          => 'active',
            ...$attrs,
        ]);
    }

    private function receiver(string $email): SendgridReceiver
    {
        return SendgridReceiver::create(['email' => $email, 'source' => SendgridReceiver::SOURCE_MANUAL]);
    }

    public function test_crud_writes_the_sendgrid_list_and_leaves_the_mailgun_list_alone(): void
    {
        $this->actingAsAdmin();
        MailgunReceiver::create(['email' => 'both@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        // The same address may sit on both lists: uniqueness is per list.
        $id = $this->postJson('/api/v1/admin/sendgrid-receivers', ['email' => 'Both@Example.com '])
            ->assertCreated()
            ->assertJsonPath('data.email', 'both@example.com')
            ->json('data.id');

        $this->postJson('/api/v1/admin/sendgrid-receivers', ['email' => 'both@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->putJson("/api/v1/admin/sendgrid-receivers/{$id}", ['email' => 'both@example.com', 'name' => 'Ann'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ann');

        $this->assertSame(1, SendgridReceiver::count());
        $this->assertSame(1, MailgunReceiver::count());
        $this->assertNull(MailgunReceiver::query()->value('name'));

        $this->deleteJson("/api/v1/admin/sendgrid-receivers/{$id}")->assertNoContent();

        $this->assertSoftDeleted('sendgrid_receivers', ['id' => $id]);
        $this->assertSame(1, MailgunReceiver::count());
    }

    public function test_each_list_lists_and_counts_only_its_own_rows(): void
    {
        $this->actingAsAdmin();
        $this->receiver('sg@example.com');
        MailgunReceiver::create(['email' => 'mg@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        $this->getJson('/api/v1/admin/sendgrid-receivers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'sg@example.com');
        $this->getJson('/api/v1/admin/sendgrid-receivers/count')->assertExactJson(['total' => 1]);
        $this->getJson('/api/v1/admin/mailgun-receivers')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'mg@example.com');
    }

    public function test_an_import_fills_the_sendgrid_list(): void
    {
        $this->actingAsAdmin();

        $id = $this->post('/api/v1/admin/sendgrid-receivers/import', [
            'file' => $this->csv([['Email'], ['a@example.com'], ['b@example.com']]),
        ], ['Accept' => 'application/json'])->assertAccepted()->json('data.id');

        // The queue is sync under test, so the job has already run.
        $this->getJson("/api/v1/admin/sendgrid-receivers/imports/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.imported', 2);
        $this->assertSame(['a@example.com', 'b@example.com'], SendgridReceiver::orderBy('email')->pluck('email')->all());
        $this->assertSame(0, MailgunReceiver::count());
    }

    public function test_a_run_mails_the_sendgrid_list_and_records_it_in_the_sendgrid_tables(): void
    {
        $key = $this->key();
        $receiver = $this->receiver('person@example.com');
        // Same id space, other list: must be neither selected nor stamped.
        $mailgun = MailgunReceiver::create(['email' => 'person@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        $result = app(ReceiverCampaignSender::class)->sendChunk($key, app('mailer'), [(int) $receiver->id]);

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(MailgunReceiverMessage::class, function (MailgunReceiverMessage $mail) use ($receiver): bool {
            $headers = $mail->headers()->text['List-Unsubscribe'];

            return $mail->hasTo('person@example.com')
                && str_contains($headers, '/api/v1/sendgrid-unsubscribe/' . $receiver->unsubscribe_token);
        });

        $this->assertNotNull($receiver->fresh()->last_sent_at);
        $this->assertSame(1, $receiver->fresh()->sent_count);
        $this->assertDatabaseHas('sendgrid_receiver_sends', [
            'sendgrid_key_id'      => $key->id,
            'sendgrid_receiver_id' => $receiver->id,
            'status'               => 'sent',
        ]);
        $this->assertDatabaseHas('sendgrid_receiver_daily_claims', ['sendgrid_receiver_id' => $receiver->id, 'channel' => 'sendgrid']);

        $this->assertNull($mailgun->fresh()->last_sent_at);
        $this->assertSame(0, DB::table('receiver_daily_claims')->count());
        $this->assertSame(0, DB::table('mailgun_receiver_sends')->count());
    }

    public function test_the_selector_reads_the_list_the_credential_names(): void
    {
        $this->receiver('sg1@example.com');
        $this->receiver('sg2@example.com');
        MailgunReceiver::create(['email' => 'mg@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);
        $mailgunKey = MailgunKey::create([
            'name' => 'Mailgun', 'domain' => 'mail.example.com', 'api_key' => 'key-test',
            'batch_size' => 100, 'selection_order' => MailgunReceiver::ORDER_OLDEST, 'status' => 'active',
        ]);

        $selector = app(MailgunReceiverSelector::class);

        $this->assertSame(2, $selector->eligible($this->key()));
        $this->assertSame(1, $selector->eligible($mailgunKey));
    }

    public function test_settings_have_no_send_switch_and_count_the_sendgrid_list(): void
    {
        $this->actingAsAdmin();
        $key = $this->key(['message_subject' => null, 'message_html' => null]);
        $this->receiver('sg@example.com');

        $data = $this->getJson("/api/v1/admin/sendgrid-keys/{$key->id}/receiver-settings")->assertOk()->json('data');

        $this->assertArrayNotHasKey('send_enabled', $data);
        $this->assertSame(1, $data['eligible_count']);
        $this->assertSame('no message is configured for this credential', $data['blocked_reason']);
    }

    public function test_run_now_refuses_a_key_without_a_from_address(): void
    {
        $this->actingAsAdmin();
        Queue::fake();
        $key = $this->key(['from_address' => null]);

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/receiver-run")
            ->assertConflict()
            ->assertJsonPath('ok', false);

        Queue::assertNothingPushed();
    }

    public function test_run_now_queues_a_configured_key(): void
    {
        $this->actingAsAdmin();
        Queue::fake();
        $key = $this->key();

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/receiver-run")
            ->assertOk()
            ->assertJsonPath('ok', true);

        Queue::assertPushed(SendSendgridReceiverCampaignJob::class, fn ($job): bool => $job->credentialId === $key->id);
    }

    public function test_unsubscribing_from_the_sendgrid_list_stops_the_address_on_both_lists(): void
    {
        $receiver = $this->receiver('leave@example.com');
        MailgunReceiver::create(['email' => 'leave@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        // The visible link only renders a confirm page, posting back to this list.
        $this->get("/api/v1/sendgrid-unsubscribe/{$receiver->unsubscribe_token}")
            ->assertOk()
            ->assertSee('/api/v1/sendgrid-unsubscribe/' . $receiver->unsubscribe_token . '/confirm', false);
        $this->assertNull($receiver->fresh()->unsubscribed_at);

        $this->post("/api/v1/sendgrid-unsubscribe/{$receiver->unsubscribe_token}")->assertOk();

        $this->assertNotNull($receiver->fresh()->unsubscribed_at);
        $this->assertTrue(MailgunSuppression::query()->where('email', 'leave@example.com')->exists());
        // The shared suppression list keeps the Mailgun row out of every send.
        $this->assertSame(0, MailgunReceiver::query()->sendable()->notSuppressed()->count());
    }

    public function test_reset_sends_clears_only_the_sendgrid_list(): void
    {
        $this->actingAsAdmin();
        $receiver = $this->receiver('sg@example.com');
        $receiver->forceFill(['last_sent_at' => now(), 'sent_count' => 3])->save();
        $mailgun = MailgunReceiver::create(['email' => 'mg@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);
        $mailgun->forceFill(['last_sent_at' => now(), 'sent_count' => 2])->save();

        $this->postJson('/api/v1/admin/sendgrid-receivers/reset-sends')->assertExactJson(['affected' => 1]);

        $this->assertNull($receiver->fresh()->last_sent_at);
        $this->assertSame(2, $mailgun->fresh()->sent_count);
    }

    public function test_the_delete_command_targets_the_list_it_is_given(): void
    {
        $this->receiver('sg@example.com');
        MailgunReceiver::create(['email' => 'mg@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        $this->artisan('mailgun:delete-receivers', ['--list' => 'sendgrid', '--force' => true])->assertSuccessful();

        $this->assertSame(0, SendgridReceiver::withTrashed()->count());
        $this->assertSame(1, MailgunReceiver::count());

        $this->artisan('mailgun:delete-receivers', ['--list' => 'brevo', '--force' => true])->assertFailed();
    }

    /** @param  list<array<int, string>>  $rows */
    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sgr') . '.csv';
        $this->tempFiles[] = $path;

        $writer = new CsvWriter();
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new UploadedFile($path, 'receivers.csv', null, null, true);
    }
}
