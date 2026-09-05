<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SmtpCredential;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Loads the Email Configs from `database/seeders/data/smtp-credentials.json`.
 *
 *   php artisan db:seed --class=SmtpCredentialSeeder
 *
 * WHY THE DATA IS NOT IN THIS FILE. Every row carries a working mailbox or
 * Mailgun SMTP password. A seeder is a tracked, deployed artefact that gets
 * copied to servers, read by anyone with repository access and kept in history
 * forever; the JSON it reads is gitignored and stays on the machine that needs
 * it. Same result from one command, without twenty live passwords in the tree.
 *
 * A missing data file is reported and skipped, never fatal — `db:seed` on a
 * fresh checkout must not fail just because the operator has not placed their
 * own credentials yet.
 *
 * DESTRUCTIVE: every run empties `smtp_credentials` and refills it from the file.
 * The file is the whole truth, so a row deleted from it disappears from the
 * table, and rows always come back in the file's order with fresh ids.
 *
 * Two consequences worth knowing before running it against real data:
 *
 *  - `smtp_receiver_sends` cascades on delete, so each credential's send history
 *    goes with it. The cross-channel `receiver_daily_claims` do NOT — they record
 *    that a person was mailed, not who mailed them, so re-seeding cannot resurrect
 *    someone's slot and mail them twice in a day.
 *  - Any `message_subject` / `message_html` / `message_template` authored in the
 *    admin is destroyed, because the credential row holding it is destroyed.
 *    Each credential is then re-seeded with a FRESH message built from the
 *    source site's promotion template, site names stripped — the same starting
 *    copy the admin's "Reset to promotion template" button produces, via the
 *    same {@see MailgunReceiverTemplate::seed()}. So a re-seed does not leave
 *    the configs unable to send; it resets their copy to the shared default.
 *
 * The wipe happens only AFTER the file has been read and found to hold rows, and
 * the whole thing runs in one transaction. A missing or malformed file therefore
 * leaves the existing credentials untouched rather than emptying the table and
 * having nothing to put back.
 */
class SmtpCredentialSeeder extends Seeder
{
    /** Relative to database_path(), so the file sits beside the other seeder data. */
    private const string DATA_FILE = 'seeders/data/smtp-credentials.json';

    public function run(): void
    {
        $path = database_path(self::DATA_FILE);

        if (! is_file($path)) {
            $this->command?->warn(
                "No SMTP credential data at database/{$this->relative()} — skipping. "
                . 'Copy the example file and fill in your own servers.',
            );

            return;
        }

        try {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->command?->error("database/{$this->relative()} is not valid JSON: {$e->getMessage()}");

            return;
        }

        if (! is_array($rows) || $rows === []) {
            $this->command?->warn("database/{$this->relative()} holds no rows — nothing seeded.");

            return;
        }

        $created = 0;

        // Resolved ONCE, outside the loop: every credential starts from the same
        // promotion copy, and re-reading it per row would be twenty identical
        // queries for one answer.
        $seed = MailgunReceiverTemplate::seed();
        $template = MailgunReceiverTemplate::merged($seed['template']);
        // An empty seed stores an empty body, so blockedReason() reports "no
        // message is configured" rather than the credential shipping a bare
        // shell with nothing but an unsubscribe link in it.
        $html = MailgunReceiverTemplate::isEmpty($template)
            ? null
            : MailgunReceiverTemplate::render($template);

        if ($html === null) {
            $this->command?->warn(
                'The source promotion template is empty, so credentials are seeded with no '
                . 'message. Write one per credential in Email Configs before running a campaign.',
            );
        }

        // One transaction around the wipe and the refill. Without it, a bad row
        // partway through leaves the table half-populated and the credentials
        // that were there gone — the worst of both states.
        $deleted = DB::transaction(function () use ($rows, $seed, $template, $html, &$created): int {
            // A plain DELETE, not TRUNCATE. `smtp_receiver_sends` holds a foreign
            // key into this table, and MySQL refuses to truncate a table another
            // one references; DELETE lets the cascade do its job instead.
            $removed = SmtpCredential::query()->delete();

            foreach ($rows as $index => $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    $this->command?->warn("Row {$index} has no name — skipped.");

                    continue;
                }

                $username = trim((string) ($row['username'] ?? ''));

                SmtpCredential::create([
                    'name'     => $name,
                    'type'     => $this->type($row),
                    'host'     => trim((string) ($row['host'] ?? '')),
                    'port'     => (int) ($row['port'] ?? 587),
                    'username' => $username,
                    'password' => (string) ($row['password'] ?? ''),
                    'encryption' => $this->encryption($row),
                    // The sender is ALWAYS the username. These mailboxes
                    // authenticate as the address they send from, and a From on
                    // another address is what gets a message accepted by the
                    // relay and then dropped by the recipient's SPF check.
                    'from_address' => $username,
                    'from_name'    => $row['from_name'] ?? null,
                    'status'       => $row['status'] ?? SmtpCredential::STATUS_ACTIVE,

                    // Every config gets its OWN copy of the message, not a
                    // shared reference to one: each row is independently
                    // editable in Email Configs afterwards, and two credentials
                    // pointing at one template would make editing either of them
                    // silently rewrite the other's mail.
                    //
                    // A per-row "subject" in the JSON wins over the seed, so a
                    // config that needs its own subject line can carry one
                    // without giving up the shared body.
                    'message_subject'  => trim((string) ($row['subject'] ?? '')) ?: $seed['subject'],
                    'message_template' => $template,
                    'message_html'     => $html,
                ]);

                $created++;
            }

            return $removed;
        });

        $this->command?->info(
            "SMTP credentials reseeded: {$deleted} removed, {$created} inserted.",
        );
    }

    /**
     * The declared type, defaulting to an own server.
     *
     * Deliberately NOT inferred from the host. `smtp.mailgun.org` would be an
     * easy guess today and a wrong one the moment a row points at a different
     * gateway, so an unrecognised value falls back to the plain case rather than
     * to a cleverer one.
     */
    private function type(array $row): string
    {
        $type = (string) ($row['type'] ?? SmtpCredential::TYPE_OWN_SMTP);

        return in_array($type, SmtpCredential::TYPES, true)
            ? $type
            : SmtpCredential::TYPE_OWN_SMTP;
    }

    /**
     * Encryption as declared, or derived from the port when the file omits it.
     *
     * 465 is implicit TLS and 587 is STARTTLS by convention, which covers every
     * row in practice — but an explicit value in the file always wins, because
     * the convention is a convention and not a rule.
     */
    private function encryption(array $row): string
    {
        $declared = (string) ($row['encryption'] ?? '');

        if (in_array($declared, SmtpCredential::ENCRYPTIONS, true)) {
            return $declared;
        }

        return (int) ($row['port'] ?? 0) === 465
            ? SmtpCredential::ENCRYPTION_SSL
            : SmtpCredential::ENCRYPTION_TLS;
    }

    private function relative(): string
    {
        return self::DATA_FILE;
    }
}
