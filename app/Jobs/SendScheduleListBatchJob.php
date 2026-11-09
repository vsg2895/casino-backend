<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PromotionMailerException;
use App\Models\CampaignReceiver;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\ScheduleSendHistory;
use App\Models\Site;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
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
 * Receivers get THEIR list's unsubscribe link, so an opt-out lands where the
 * list will honour it. The warmup list has no opt-out of its own (it is seed
 * mailboxes), so its message carries an inert link, exactly as a warmup send
 * of the promotion template does.
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
        $template = $site?->promotionEmail ?? $site?->promotionEmailOrDefault();

        if ($site === null || $template === null || ! $template->active) {
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

        $schedule = EmailSchedule::find($this->scheduleId);
        $context = ScheduleSendContext::for(
            $schedule,
            $this->scheduleId,
            $this->siteId,
            $this->audience,
            $this->provider,
            $this->credentialId,
            $resolved->fromAddress,
        );

        $resting = array_flip(ScheduleSendHistory::restingAmong(
            $this->siteId,
            EmailSchedule::TEMPLATE_PROMOTION,
            $schedule?->cooldownDays() ?? EmailSchedule::MIN_COOLDOWN_DAYS,
            $recipients->pluck('email')->all(),
        ));

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

            if (isset($resting[$email]) || ($isReceiverList && ! $this->claimDay($model, $id))) {
                $rows[] = $context->row($email, $id, ScheduleSendHistory::STATUS_SKIPPED);
                $skipped++;
            } else {
                try {
                    $mailable = $recipient instanceof CampaignReceiver
                        ? $promotions->mailWithLinks(
                            $site,
                            $template,
                            $email,
                            $recipient->unsubscribeUrl(),
                            $recipient->unsubscribeUrl(),
                            $recipient->name,
                        )
                        : $promotions->mailFor($site, $template, $email, Newsletter::generateUnsubscribeToken());

                    $pacer->send($resolved->mailer, $email, $mailable->usingFromAddress($resolved->fromAddress));
                    $rows[] = $context->row($email, $id, ScheduleSendHistory::STATUS_SENT);
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

        unset($recipients, $resting);

        $this->record($rows);

        Log::info('Schedule list batch processed', [
            'schedule_id' => $this->scheduleId,
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
