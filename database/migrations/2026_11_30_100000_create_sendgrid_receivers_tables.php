<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SendGrid half of Custom Receivers.
 *
 * Four new tables, the Mailgun list's twins in their CURRENT shape (after every
 * later migration on the Mailgun side — `consent_source` dropped, `source_label`
 * added, the selection indexes tuned), plus the receiver-campaign columns on
 * `sendgrid_keys`.
 *
 * Purely additive: no existing row is read or written, and nothing on the
 * Mailgun side changes. The suppression list is NOT duplicated — both lists read
 * `mailgun_suppressions`, keyed by email.
 */
return new class extends Migration
{
    /** Matches ReceiverSource; repeated here because a migration is a historical record. */
    private const array SOURCE_LABELS = ['our_clients', 'external_clients'];

    private const string DEFAULT_SOURCE = 'our_clients';

    public function up(): void
    {
        if (! Schema::hasTable('sendgrid_receivers')) {
            Schema::create('sendgrid_receivers', function (Blueprint $table): void {
                $table->id();

                // Lowercase + trimmed by the model mutator, so the unique index
                // means one person. Unique within THIS list only — the same
                // address may also sit on the Mailgun list.
                $table->string('email', 255)->unique();
                $table->string('name', 255)->nullable();

                // How the row arrived: 'import' | 'manual'.
                $table->string('source', 10)->default('manual');
                // Whose list the address came from.
                $table->enum('source_label', self::SOURCE_LABELS)->default(self::DEFAULT_SOURCE);
                $table->timestamp('consent_recorded_at')->nullable();

                // The credential for one-click unsubscribe.
                $table->string('unsubscribe_token', 64)->unique();

                $table->boolean('is_active')->default(true);
                $table->timestamp('unsubscribed_at')->nullable();

                // Denormalised mirror of the newest successful send; the cooldown
                // filter reads this rather than aggregating the history.
                $table->timestamp('last_sent_at')->nullable()->index();
                $table->unsignedInteger('sent_count')->default(0);
                $table->text('last_error')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index('source_label', 'sendgrid_receivers_source_label_idx');
                // Keyset pagination order.
                $table->index(['created_at', 'id'], 'sendgrid_receivers_keyset_index');
                // Campaign selection: both NULL checks, then the batch order —
                // the same shape as mg_receivers_sendable_order_idx.
                $table->index(
                    ['deleted_at', 'unsubscribed_at', 'created_at', 'id'],
                    'sg_receivers_sendable_order_idx',
                );
            });
        }

        if (! Schema::hasTable('sendgrid_receiver_imports')) {
            Schema::create('sendgrid_receiver_imports', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->string('filename', 255);
                $table->enum('source_label', self::SOURCE_LABELS)->default(self::DEFAULT_SOURCE);
                $table->string('path', 255)->nullable();

                // queued | running | finished | failed
                $table->string('status', 20)->default('queued')->index();

                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('imported')->default(0);
                $table->unsignedInteger('duplicates')->default(0);
                $table->unsignedInteger('suppressed')->default(0);
                $table->unsignedInteger('rejected')->default(0);

                $table->longText('rejected_rows')->nullable();
                $table->text('error')->nullable();

                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('sendgrid_keys', function (Blueprint $table): void {
            // Sender of receiver campaigns. Nullable: schedules and the test
            // button take their sender from a site template, so an existing key
            // keeps working untouched; only a campaign refuses to run without it.
            if (! Schema::hasColumn('sendgrid_keys', 'from_address')) {
                $table->string('from_address', 255)->nullable()->after('api_key');
            }

            if (! Schema::hasColumn('sendgrid_keys', 'from_name')) {
                $table->string('from_name', 120)->nullable()->after('from_address');
            }

            // The Signed Event Webhook's verification key, copied from the
            // SendGrid account (Settings → Mail Settings → Event Webhook). A
            // PUBLIC key — it proves an event came from SendGrid and cannot
            // sign one — so it is stored plain. Null = no webhook for this
            // account; events are only accepted when some key verifies them.
            if (! Schema::hasColumn('sendgrid_keys', 'webhook_verification_key')) {
                $table->text('webhook_verification_key')->nullable()->after('from_name');
            }

            // Receiver targeting, with the same defaults as mailgun_keys and
            // smtp_credentials. No `send_enabled`: like an own SMTP server, this
            // channel runs only from "Run now", so adding the columns can never
            // start mailing anyone on its own.
            if (! Schema::hasColumn('sendgrid_keys', 'batch_size')) {
                $table->unsignedInteger('batch_size')->default(100);
            }

            if (! Schema::hasColumn('sendgrid_keys', 'selection_order')) {
                $table->string('selection_order', 10)->default('newest');
            }

            if (! Schema::hasColumn('sendgrid_keys', 'cooldown_days')) {
                $table->unsignedSmallInteger('cooldown_days')->nullable()->default(1);
            }

            if (! Schema::hasColumn('sendgrid_keys', 'receiver_sources')) {
                $table->json('receiver_sources')->nullable();
            }

            if (! Schema::hasColumn('sendgrid_keys', 'last_run_at')) {
                $table->timestamp('last_run_at')->nullable();
            }

            if (! Schema::hasColumn('sendgrid_keys', 'message_subject')) {
                $table->string('message_subject', 255)->nullable();
            }

            if (! Schema::hasColumn('sendgrid_keys', 'message_html')) {
                $table->longText('message_html')->nullable();
            }

            if (! Schema::hasColumn('sendgrid_keys', 'message_template')) {
                $table->json('message_template')->nullable();
            }
        });

        if (! Schema::hasTable('sendgrid_receiver_sends')) {
            Schema::create('sendgrid_receiver_sends', function (Blueprint $table): void {
                $table->id();

                // Cascades, like mailgun_receiver_sends: history for a deleted
                // key has no meaning.
                $table->foreignId('sendgrid_key_id')
                    ->constrained('sendgrid_keys')
                    ->cascadeOnDelete();

                // Nulled rather than cascaded: the receiver may go, the record stays.
                $table->foreignId('sendgrid_receiver_id')
                    ->nullable()
                    ->constrained('sendgrid_receivers')
                    ->nullOnDelete();

                $table->string('email', 255);

                // 'sent' | 'failed' | 'skipped'
                $table->string('status', 10)->index();
                $table->text('error')->nullable();

                $table->timestamp('sent_at');
                $table->date('sent_on');

                $table->timestamps();

                $table->unique(
                    ['sendgrid_key_id', 'sendgrid_receiver_id', 'sent_on'],
                    'sendgrid_receiver_sends_daily_unique',
                );
                $table->index(['sendgrid_key_id', 'sent_at'], 'sendgrid_receiver_sends_key_sent_index');
                $table->index('email', 'sendgrid_receiver_sends_email_index');
            });
        }

        if (! Schema::hasTable('sendgrid_receiver_daily_claims')) {
            Schema::create('sendgrid_receiver_daily_claims', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('sendgrid_receiver_id')
                    ->constrained('sendgrid_receivers')
                    ->cascadeOnDelete();

                $table->date('claim_on');

                // 'sendgrid' — diagnostic only, kept for the same shape as
                // receiver_daily_claims.
                $table->string('channel', 10);
                $table->unsignedBigInteger('credential_id');

                $table->timestamp('created_at')->nullable();

                // THE guard: one message per address per day across every key.
                $table->unique(['sendgrid_receiver_id', 'claim_on'], 'sendgrid_receiver_daily_claims_unique');
                $table->index(['claim_on', 'channel'], 'sendgrid_receiver_daily_claims_day_channel_index');
            });
        }
    }

    public function down(): void
    {
        // Children first: both reference sendgrid_receivers.
        Schema::dropIfExists('sendgrid_receiver_daily_claims');
        Schema::dropIfExists('sendgrid_receiver_sends');
        Schema::dropIfExists('sendgrid_receiver_imports');
        Schema::dropIfExists('sendgrid_receivers');

        Schema::table('sendgrid_keys', function (Blueprint $table): void {
            foreach ([
                'message_template', 'message_html', 'message_subject', 'last_run_at',
                'receiver_sources', 'cooldown_days', 'selection_order', 'batch_size',
                'webhook_verification_key', 'from_name', 'from_address',
            ] as $column) {
                if (Schema::hasColumn('sendgrid_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
