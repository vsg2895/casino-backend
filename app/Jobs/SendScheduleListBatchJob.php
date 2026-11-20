<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PromotionMailerException;
use App\Models\CampaignReceiver;
use App\Models\Contracts\TokenAddressable;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\ScheduleSendHistory;
use App\Models\Site;
use App\Models\WarmupEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\Mail\ScheduleMailResolver;
use App\Services\PromotionEmailService;
use App\Services\ScheduleRecipientService;
use App\Support\Mail\PacedDelivery;
use App\Support\Mail\ScheduleSendContext;
use App\Support\Mail\SendingAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a site's promotion email to ONE BATCH of a global list — Mailgun
 * Receivers, Sendgrid Receivers or Warmup — for a schedule.
 *
 * The list twin of {@see SendPromotionBatchJob}, which keeps the newsletter
 * audience. Only row ids travel in the payload; each is re-read here, so an
 * address that unsubscribed, was suppressed or was deleted since fan-out is not
 * mailed.
 *
 * Per address, in order:
 *  1. resting inside the cooldown → recorded `skipped`;
 *  2. a receiver list's one-email-per-day claim, taken atomically — lost means
 *     the list mailed this person today (another schedule, a credential
 *     campaign) → `skipped`;
 *  3. send through the schedule's provider → `sent` or `failed`.
 *
 * Every address gets ITS OWN list's unsubscribe link, so an opt-out lands
 * where the list will honour it. Warmup used to be the exception — a seed
 * mailbox was assumed never to unsubscribe, so its message carried an inert
 * link — and since 2026-12 it carries a real one like the rest.
 *
 * That is also what lets this job send the SUBSCRIPTION template, not just the
 * promotion and custom ones: it is built from the recipient's own token, and
 * every global list now has one, so its opt-out resolves to the list that
 * mailed them. The VERIFY template remains newsletters-only
 * ({@see \App\Models\EmailSchedule::SUBSCRIBER_ONLY_TEMPLATES}) — an
 * operator-entered address has no opt-in claim to confirm.
 *
 * Outcomes are flushed to `schedule_send_histories` every `history_flush_size`
 * attempts, so a worker killed mid-batch leaves at most one window unrecorded.
 *
 * Sending is paced like a campaign platform's: the sending account's rolling
 * window, one retry on a soft failure, and a time budget that re-queues the
 * rest instead of overrunning the job ({@see PacedDelivery}).
 */
class SendScheduleListBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    private const int HISTORY_FLUSH_SIZE = 25;

    public int $tries = 2;

    public int $backoff = 30;

    /** Same budget as the newsletter batch; MUST stay below the queue's retry_after. */
    public int $timeout;

    /**
     * @param  list<int>  $recipientIds  Row ids in the audience's own table.
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly int $siteId,
        public readonly string $audience,
        public readonly array $recipientIds,
        public readonly string $provider = EmailSchedule::PROVIDER_SMTP,
        public readonly ?int $credentialId = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('promotions.batch_timeout', 240);
    }

    public function handle(PromotionEmailService $promotions, PromotionMailerFactory $mailers): void
    {
        $model = EmailSchedule::modelForAudience($this->audience);

        if ($model === null || $this->recipientIds === []) {
            return;
        }

        $site = Site::find($this->siteId);

        if ($site === null) {
            return;
        }

        // Read BEFORE the template gate: which email this batch is sending is
        // the schedule's to say, and until 2026-11-17 the answer was always the
        // promotion template.
        $schedule = EmailSchedule::find($this->scheduleId);
        $templateKey = $schedule?->templateKey() ?? EmailSchedule::TEMPLATE_PROMOTION;
        $sendsCustom = $schedule?->usesCustomTemplate() ?? false;

        // Whether this batch is rendered by the resolver (subscribe, verify or
        // custom) or by the inline promotion path below. Since 2026-12 the
        // first two reach this job too: every global list carries its own
        // token, so both templates can be built for a receiver.
        $usesResolver = ScheduleMailResolver::handles($templateKey);

        // The promotion template is only resolved — and only has to be switched
        // on — when it is the one going out. A Custom HTML schedule carries its
        // own body, and subscribe/verify read their own per-site templates, so
        // none of them may be gated on a template they never read.
        $template = $usesResolver ? null : ($site->promotionEmail ?? $site->promotionEmailOrDefault());

        // The renderer for a non-promotion send, from the container rather than
        // injected into handle(): existing tests call that method directly with
        // exactly two arguments, and the promotion path they cover never needs
        // this. Built only when it is actually used.
        $resolver = $usesResolver ? app(ScheduleMailResolver::class) : null;

        if ($sendsCustom) {
            if ($schedule?->customContent() === null) {
                Log::warning('Schedule list batch skipped: custom email has no subject or body', [
                    'schedule_id' => $this->scheduleId,
                    'audience'    => $this->audience,
                ]);

                return;
            }
        } elseif ($usesResolver) {
            // Subscribe and verify render the site's own template for that
            // stream. A switched-off template must stop the batch here, for the
            // same reason the promotion one does: it would otherwise send a
            // message the operator has already turned off.
            if ($schedule !== null && ! $schedule->templateActiveFor($site)) {
                Log::warning('Schedule list batch skipped: the template it sends is switched off', [
                    'schedule_id' => $this->scheduleId,
                    'audience'    => $this->audience,
                    'template'    => $templateKey,
                ]);

                return;
            }
        } elseif ($template === null || ! $template->active) {
            return;
        }

        $isReceiverList = is_subclass_of($model, CampaignReceiver::class);
        $query = $model::query()->whereIn('id', $this->recipientIds);

        if ($isReceiverList) {
            $query->sendable()->notSuppressed();
        }

        $recipients = $query->get();

        try {
            $resolved = $mailers->resolve($this->provider, $this->credentialId);
        } catch (PromotionMailerException $e) {
            Log::error('Schedule list batch skipped: mail transport unavailable', [
                'schedule_id' => $this->scheduleId,
                'audience'    => $this->audience,
                'provider'    => $this->provider,
                'batch_size'  => count($this->recipientIds),
                'error'       => $e->getMessage(),
            ]);

            return;
        }

        // The address the history records is the one the messages actually go
        // out as: a Custom HTML schedule's own, the transport's authenticated
        // mailbox on an .env transport, or the SITE'S TEMPLATE address on a
        // stored per-site credential — see EmailSchedule::fromAddressFor().
        $fromAddress = $schedule?->fromAddressFor($resolved->fromAddress, $site) ?? $resolved->fromAddress;

        $context = ScheduleSendContext::for(
            $schedule,
            $this->scheduleId,
            $this->siteId,
            $this->audience,
            $this->provider,
            $this->credentialId,
            $fromAddress,
        );

        // Rested per TEMPLATE, not per site: an address that received this
        // site's promotion is not resting against its custom campaign, and the
        // admin set a cooldown for the campaign they configured.
        $resting = array_flip(ScheduleSendHistory::restingAmong(
            $this->siteId,
            $context->template,
            $schedule?->cooldownDays() ?? EmailSchedule::MIN_COOLDOWN_DAYS,
            $recipients->pluck('email')->all(),
        ));

        /*
         * Anyone who opted out of THIS SITE, on any list and through any
         * template.
         *
         * A Custom Receivers address has its own `unsubscribed_at`, which the
         * query above already honours — but that is the LIST's opt-out. This is
         * the SITE's: someone who unsubscribed from a newsletter of this site
         * must not then be mailed by the same site because their address also
         * sits on a receivers list. Until 2026-11-17 nothing checked it on this
         * path. Warmup is included, for the same reason — a seed mailbox that
         * opted out of a site is still an address that opted out of that site.
         *
         * Re-read here rather than trusted from fan-out, so an opt-out that
         * lands while the campaign is in flight still takes effect.
         */
        $optedOut = ($schedule?->honoursOptOut() ?? true)
            ? array_flip(ScheduleRecipientService::optedOutAmong($this->siteId, $recipients->pluck('email')->all()))
            : [];

        $flushEvery = max(1, (int) config('promotions.history_flush_size', self::HISTORY_FLUSH_SIZE));
        $rows = [];
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        // Throttled per sending account, soft failures retried once, and a time
        // budget instead of a killed job — see PacedDelivery.
        $pacer = PacedDelivery::forAccount(
            SendingAccount::forProvider($this->provider, $this->credentialId),
            $this->timeout,
            '[Schedule]',
        );
        /** @var list<int> $deferred Ids handed to a follow-up batch. */
        $deferred = [];
        /** @var list<string> $delivered Addresses actually mailed, for the warmup stamp. */
        $delivered = [];
        $recipients = $recipients->values();

        foreach ($recipients as $index => $recipient) {
            $email = (string) $recipient->email;
            $id = (int) $recipient->id;

            // Out of time: everything from here on — this address included,
            // before its claim or a slot was taken — goes to a new batch.
            if ($pacer->budgetSpent()) {
                $deferred = $recipients->slice((int) $index)->pluck('id')->map(static fn ($v): int => (int) $v)->values()->all();

                break;
            }

            // The site's opt-out comes FIRST: before the daily claim, so an
            // address nobody may mail does not burn its slot for the day.
            if (isset($optedOut[$email]) || isset($resting[$email]) || ($isReceiverList && ! $this->claimDay($model, $id))) {
                $rows[] = $context->row($email, $id, ScheduleSendHistory::STATUS_SKIPPED);
                $skipped++;
            } else {
                try {
                    // An address's opt-out belongs to the list it is actually
                    // on, so that is the link every template carries. Warmup
                    // used to be the exception — its message carried an INERT
                    // link, on the view that a seed mailbox never unsubscribes
                    // — and since 2026-12 it is not: the list has its own token
                    // and its own endpoint, so this is a working opt-out for
                    // every audience.
                    if ($recipient instanceof TokenAddressable) {
                        // Covers a row that predates its list's token column and
                        // has not been backfilled yet — it gets one now rather
                        // than a link ending in nothing.
                        $recipient->ensureUnsubscribeToken();
                    }

                    $unsubscribeUrl = $recipient instanceof TokenAddressable
                        ? $recipient->unsubscribeUrl()
                        : '';

                    if ($usesResolver) {
                        /** @var EmailSchedule $schedule — non-null: $usesResolver came from it. */
                        $mailable = $resolver?->build(
                            $schedule,
                            $site,
                            $email,
                            // The row itself, so subscribe and verify are built
                            // from THIS address's token. Custom HTML ignores it.
                            $recipient instanceof TokenAddressable ? $recipient : null,
                            $unsubscribeUrl,
                            $unsubscribeUrl,
                        );
                    } else {
                        // Every audience this job serves is token-addressed, so
                        // the promotion email carries a real opt-out too —
                        // warmup included, which until 2026-12 was given a
                        // throwaway token and therefore a dead link. The
                        // `mailFor` fallback stays for a list that somehow
                        // reaches here without one: a send with no working
                        // unsubscribe is worse than an inert one.
                        $mailable = $recipient instanceof TokenAddressable
                            ? $promotions->mailWithLinks(
                                $site,
                                $template,
                                $email,
                                $unsubscribeUrl,
                                $unsubscribeUrl,
                                $recipient->recipientName(),
                            )
                            : $promotions->mailFor($site, $template, $email, Newsletter::generateUnsubscribeToken());
                    }

                    // A Custom HTML schedule may send from its own address; every
                    // other template keeps the transport's authenticated mailbox.
                    $pacer->send($resolved->mailer, $email, $mailable->usingFromAddress($fromAddress));
                    $rows[] = $context->row($email, $id, ScheduleSendHistory::STATUS_SENT);
                    $delivered[] = $email;
                    $sent++;
                } catch (Throwable $e) {
                    // One bad address never aborts the batch or triggers a retry
                    // that would re-send to everyone before it.
                    Log::warning('Schedule list send failed for a recipient', [
                        'schedule_id' => $this->scheduleId,
                        'audience'    => $this->audience,
                        'email'       => $email,
                        'error'       => $e->getMessage(),
                    ]);
                    $rows[] = $context->row($email, $id, ScheduleSendHistory::STATUS_FAILED, $e->getMessage());
                    $failed++;
                }
            }

            if (count($rows) >= $flushEvery) {
                $this->record($rows);
                $rows = [];
            }
        }

        unset($recipients, $resting, $optedOut);

        $this->record($rows);
        $this->stampWarmupCooldown($delivered);

        Log::info('Schedule list batch processed', [
            'schedule_id' => $this->scheduleId,
            'template'    => $context->template,
            'audience'    => $this->audience,
            'sent'        => $sent,
            'failed'      => $failed,
            'skipped'     => $skipped,
            'total'       => count($this->recipientIds),
            'deferred'    => count($deferred),
            'rate'        => $pacer->rate(),
        ]);

        // History is written above, so the continuation never runs ahead of it.
        if ($deferred !== []) {
            self::dispatch($this->scheduleId, $this->siteId, $this->audience, $deferred, $this->provider, $this->credentialId);

            Log::info('[Schedule] Time budget reached; remaining addresses re-queued.', [
                'schedule_id' => $this->scheduleId,
                'audience'    => $this->audience,
                'requeued'    => count($deferred),
            ]);
        }
    }

    /**
     * Start the warmup cooldown on the seed addresses this batch just mailed.
     *
     * A schedule sending to the warmup list is real traffic to those mailboxes,
     * so it has to move `warmup_emails.last_sent_at` exactly as a warmup run
     * does ({@see \App\Jobs\SendWarmupBatchJob::markContacted()}). Without it
     * the two features worked from different pictures of the same list: a
     * warmup run would re-contact an address a schedule had mailed minutes
     * earlier, which is the opposite of what a cooldown is for.
     *
     * DELIVERED addresses only — a failed send must not start a cooldown, or a
     * permanently broken address is retried once every N days forever instead
     * of on the next run.
     *
     * Best effort: the mail is already out, and losing the stamp only makes
     * these addresses eligible again sooner. It must never fail a sent batch.
     *
     * @param  list<string>  $delivered
     */
    private function stampWarmupCooldown(array $delivered): void
    {
        if ($delivered === [] || $this->audience !== EmailSchedule::AUDIENCE_WARMUP) {
            return;
        }

        try {
            WarmupEmail::markContacted($delivered);
        } catch (Throwable $e) {
            Log::warning('Could not stamp warmup cooldown after a schedule send', [
                'schedule_id' => $this->scheduleId,
                'addresses'   => count($delivered),
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Take the receiver's slot for today on its own list.
     *
     * The same unique (receiver, day) claim the list's credential campaigns
     * take, so a schedule and a credential can never both mail one person on
     * one day. A violation means somebody already has today — a skip.
     *
     * @param  class-string<CampaignReceiver>  $model
     */
    private function claimDay(string $model, int $receiverId): bool
    {
        try {
            DB::table($model::dailyClaimsTable())->insert([
                $model::receiverForeignKey() => $receiverId,
                'claim_on'                   => Carbon::now()->toDateString(),
                'channel'                    => 'schedule',
                'credential_id'              => $this->scheduleId,
                'created_at'                 => now(),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Best-effort: a history write failure is logged, never allowed to fail a
     * batch whose messages have already gone out.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function record(array $rows): void
    {
        try {
            ScheduleSendHistory::record($rows);
        } catch (Throwable $e) {
            Log::warning('Schedule history write failed', [
                'schedule_id' => $this->scheduleId,
                'count'       => count($rows),
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Schedule list batch job failed', [
            'schedule_id' => $this->scheduleId,
            'audience'    => $this->audience,
            'batch_size'  => count($this->recipientIds),
            'error'       => $e->getMessage(),
        ]);
    }
}
