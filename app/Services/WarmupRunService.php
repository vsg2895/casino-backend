<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWarmupCampaignJob;
use App\Models\Site;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starting, stopping and reporting on warmup runs.
 *
 * Everything the warmup screen does to a RUN, as opposed to the list itself:
 * the audience it would reach, the queued send behind its cross-process lock,
 * the recovery path that clears that lock, and the summary of the last run.
 * The selection itself is {@see WarmupRecipientService}; the fan-out is
 * {@see SendWarmupCampaignJob}. This class only coordinates the two.
 */
final class WarmupRunService
{
    /** How long the "a warmup run is in flight" lock is held for. */
    public const int RUN_LOCK_SECONDS = 900;

    /**
     * Addresses the batch preview will show at most.
     *
     * The dialog is a sanity check before pressing Send — "are these the people
     * I meant" — not a data export, and a whole-list run can be tens of
     * thousands of rows. The response says when it truncated.
     */
    public const int PREVIEW_LIMIT = 200;

    /** Rows per database round-trip while collecting the preview. */
    private const int PREVIEW_READ_CHUNK = 200;

    private ?Site $site = null;

    private bool $siteResolved = false;

    public function __construct(
        private readonly WarmupRecipientService $recipients,
        private readonly EmailTemplateCatalog $templates,
    ) {}

    /**
     * Queue a warmup run: one site's email template, sent to a chosen number of
     * addresses — or to the whole list when no limit is given.
     *
     * This only RECORDS the run and queues the fan-out; streaming the selection
     * and dispatching batches happens in {@see SendWarmupCampaignJob} on the
     * low-priority queue, so a long list never occupies a php-fpm worker.
     *
     * Guarded by a cross-process lock rather than a disabled button: two
     * concurrent runs would mail the same seed addresses twice and start their
     * cooldowns from the wrong moment, and the guard has to hold across tabs and
     * app servers. The lock is released by the fan-out job, and expires on its own
     * if a worker dies.
     */
    public function start(string $template, ?int $limit, ?int $cooldown, ?int $userId): QueuedRunOutcome
    {
        if ($this->recipients->available() === 0) {
            return QueuedRunOutcome::refused(
                QueuedRunRefusal::Unsendable,
                'The warmup list is empty. Add or import addresses first.',
            );
        }

        // Pinned, never chosen: warmup always sends as one brand. Resolved here
        // rather than trusted from the request, so the API cannot be asked for a
        // different site and a stale admin bundle cannot send as one.
        $site = $this->site();

        if ($site === null) {
            return QueuedRunOutcome::refused(QueuedRunRefusal::Unsendable, $this->missingSiteMessage());
        }

        // Counted BEFORE the lock so an unsatisfiable run never blocks a valid
        // one. A cooldown that leaves nobody eligible is an ordinary outcome, not
        // a validation error — the admin needs to be told, not corrected.
        $recipientCount = $this->recipients->count($limit, $cooldown);

        if ($recipientCount === 0) {
            return QueuedRunOutcome::refused(
                QueuedRunRefusal::Unsendable,
                $cooldown === null
                    ? 'No addresses are available to send to.'
                    : sprintf(
                        'Every address on the list has been contacted within the last %d day(s). Lower the cooldown or add more addresses.',
                        $cooldown,
                    ),
            );
        }

        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), self::RUN_LOCK_SECONDS);

        if (! $lock->get()) {
            return QueuedRunOutcome::refused(
                QueuedRunRefusal::AlreadyRunning,
                'A warmup run is already in progress. Wait for it to finish before starting another.',
            );
        }

        // EVERYTHING between taking the lock and dispatching must release it on
        // failure. Without this, an exception here (a column too small for the
        // template key, a queue that will not accept the job) left the lock held
        // with no job in existence to free it, and every later attempt answered
        // "a warmup run is already in progress" until the 15-minute TTL expired.
        try {
            $send = WarmupSend::create([
                'site_id' => $site->id,
                'user_id' => $userId,
                'template' => $template,
                'requested_count' => $limit,
                'cooldown_days' => $cooldown,
            ]);

            // The owner token travels with the job so only that job can release
            // this exact lock — a slower earlier run can never free a newer one's.
            SendWarmupCampaignJob::dispatch($send->id, $site->id, $template, $limit, $lock->owner(), $cooldown);
        } catch (Throwable $e) {
            $lock->release();

            Log::error('Warmup run could not be queued; the run lock was released', [
                'site_id' => $site->id,
                'template' => $template,
                'error' => $e->getMessage(),
            ]);

            return QueuedRunOutcome::refused(
                QueuedRunRefusal::QueueFailed,
                'The warmup run could not be queued: '.$e->getMessage(),
            );
        }

        Log::info('Warmup run queued', [
            'warmup_send_id' => $send->id,
            'site_id' => $site->id,
            'template' => $template,
            'scope' => $limit === null ? 'all' : $limit,
            'cooldown_days' => $cooldown,
            'recipients' => $recipientCount,
            'admin_id' => $userId,
        ]);

        $message = sprintf(
            'Warmup queued: %s template for %s to %s.',
            $this->templates->label($template),
            $site->name,
            $limit === null
                ? "all {$recipientCount} address(es)"
                : sprintf(
                    '%d most recently added address(es)%s',
                    $recipientCount,
                    $cooldown === null
                        ? ''
                        : " not contacted in the last {$cooldown} day(s)",
                ),
        );

        return QueuedRunOutcome::queued($recipientCount, $message, $send->id);
    }

    /**
     * Stop the current warmup run and free the lock.
     *
     * THIS IS THE RECOVERY PATH, so it is built never to fail. It is the only way
     * back from a wedged run, and an endpoint that 500s while clearing a wedge is
     * worse than no endpoint at all — which is exactly what happened when it
     * queried `cancelled_at` before that column had been migrated.
     *
     * Ordered by importance, and each step isolated from the next:
     *
     *  1. FREE THE LOCK. This is what actually unblocks the operator, and it
     *     touches only the cache — no schema, no migration, nothing to be out of
     *     date. It happens first so that a later failure cannot prevent it.
     *     forceRelease, not release: the owner token belongs to the job, and the
     *     case that matters most is precisely when no job exists to hold it.
     *  2. MARK THE RUN CANCELLED, best effort. This stops queued batches from
     *     sending. If the column is missing (code deployed ahead of its
     *     migration) or the database is unhappy, it is logged and skipped — the
     *     lock is already free, so the operator is unblocked either way.
     *
     * Never throws, and says honestly which of the two steps took effect.
     *
     * @return array{lock_freed: bool, warmup_send_id: int|null, queued_work_stopped: bool, message: string}
     */
    public function cancel(): array
    {
        // Step 1 — the part that must always work.
        $lockFreed = true;

        try {
            Cache::lock(SendWarmupCampaignJob::runLockKey())->forceRelease();
        } catch (Throwable $e) {
            $lockFreed = false;
            Log::error('Warmup cancel: could not free the run lock', ['error' => $e->getMessage()]);
        }

        // Step 2 — best effort, never allowed to fail the request.
        $stoppedId = null;
        $queuedWorkStopped = false;

        try {
            $stopped = WarmupSend::query()
                ->whereNull('cancelled_at')
                ->latest('id')
                ->first();

            if ($stopped !== null) {
                $stopped->update(['cancelled_at' => now()]);
                $stoppedId = $stopped->id;
            }

            $queuedWorkStopped = true;
        } catch (Throwable $e) {
            // The overwhelmingly likely cause is the `cancelled_at` migration not
            // having run yet on this environment. Report it rather than hiding it.
            Log::warning('Warmup cancel: could not mark the run cancelled', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'lock_freed' => $lockFreed,
            'warmup_send_id' => $stoppedId,
            'queued_work_stopped' => $queuedWorkStopped,
            'message' => $this->cancelMessage($lockFreed, $queuedWorkStopped, $stoppedId),
        ];
    }

    /**
     * Who a run with these settings would reach right now.
     *
     * Uses the SAME {@see WarmupRecipientService} the send uses, so the number the
     * admin sees before clicking is the number that gets mailed. It also carries
     * the cooldown bounds, so the admin's number input takes its min/max from the
     * server rather than hard-coding them in two places.
     *
     * @return array<string, int|string|null>
     */
    public function audience(?int $limit, ?int $cooldown): array
    {
        $total = $this->recipients->available();
        $eligible = $this->recipients->eligible($cooldown);

        return [
            'total' => $total,
            'eligible' => $eligible,
            'recipients' => $limit === null ? $eligible : min($eligible, $limit),
            'count' => $limit,
            'cooldown_days' => $cooldown,
            'min_cooldown_days' => WarmupSend::MIN_COOLDOWN_DAYS,
            'max_cooldown_days' => WarmupSend::MAX_COOLDOWN_DAYS,
            'default_cooldown_days' => $this->defaultCooldownDays(),
            // The pinned warmup site, for the dialog to display read-only.
            'site_id' => $this->site()?->id,
            'site_name' => $this->site()?->name,
            'site_slug' => (string) config('warmup.site_slug', ''),
        ];
    }

    /**
     * The ACTUAL ADDRESSES a run with these settings would take, in send order.
     *
     * READ-ONLY. It walks the SAME {@see WarmupRecipientService} stream the
     * fan-out walks, with the same limit and the same cooldown, so the list shown
     * is the list that would be mailed and in the same order. Nothing is written:
     * no `last_sent_at`, no history row, no lock, no job.
     *
     * Capped at `PREVIEW_LIMIT` rows; `meta` carries the real totals so the
     * screen can say "showing the first 200 of 4 812".
     *
     * @return array{rows: list<WarmupEmail>, meta: array<string, int|bool|null>}
     */
    public function preview(?int $limit, ?int $cooldown): array
    {
        $eligible = $this->recipients->eligible($cooldown);
        // What the run would reach: the requested cap, or everything eligible.
        $wouldReach = $limit === null ? $eligible : min($eligible, $limit);
        // What this preview shows: never more than the cap above, never more
        // than PREVIEW_LIMIT.
        $show = min($wouldReach, self::PREVIEW_LIMIT);

        /** @var list<WarmupEmail> $rows */
        $rows = [];

        if ($show > 0) {
            $this->recipients->eachChunk(
                $show,
                $cooldown,
                min($show, self::PREVIEW_READ_CHUNK),
                function (Collection $chunk) use (&$rows): void {
                    foreach ($chunk as $row) {
                        $rows[] = $row;
                    }
                },
            );
        }

        return [
            'rows' => $rows,
            'meta' => [
                // The same three numbers the audience summary reports, so the two
                // can never disagree on the screen that shows both.
                'total' => $this->recipients->available(),
                'eligible_count' => $eligible,
                'would_reach' => $wouldReach,
                // What is on screen, and the ceiling that produced it.
                'preview_count' => count($rows),
                'preview_limit' => self::PREVIEW_LIMIT,
                'truncated' => $wouldReach > count($rows),
                'cooldown_days' => $cooldown,
                'count' => $limit,
            ],
        ];
    }

    /**
     * The MOST RECENT warmup run, summarised — or null if none has happened.
     *
     * WHICH NUMBER IS THE ANSWER. Three different counts exist for one run and
     * they are not interchangeable:
     *
     *   requested_count  what the admin typed (null = "everyone")
     *   queued_count     what the picker actually found and queued
     *   sent_count       what the transport accepted
     *
     * `sent_count` leads, because "I sent 250" means 250 arrived at the
     * provider, not 250 were asked for. The other two ride along so a shortfall
     * explains itself: asking for 250 with only 180 off cooldown is a cooldown
     * to lower, while 250 queued and 243 sent is seven bounces to look at.
     *
     * IN-PROGRESS IS A REAL STATE and must not read as a finished total. A run
     * opened mid-flight has written only the rows its finished batches wrote,
     * so `sent_count` is a partial. `in_progress` lets the screen say so
     * instead of showing a number that will grow while it is being read.
     *
     * The staleness bound is the run lock's own TTL: after that window the lock
     * is gone and no job can still be queueing for this run, so a run with
     * nothing recorded is finished-and-reached-nobody rather than pending. An
     * unbounded check would leave a crashed fan-out "in progress" forever.
     *
     * Two queries, both on indexed columns, regardless of how many runs or
     * recipients exist: one for the header, one grouped count for the outcomes.
     *
     * @return array<string, int|string|bool|null>|null
     */
    public function lastRun(): ?array
    {
        $send = WarmupSend::query()
            ->with(['site:id,name', 'user:id,name'])
            ->latest('id')
            ->first();

        if ($send === null) {
            return null;
        }

        // One grouped read rather than a count per status.
        $byStatus = WarmupSendRecipient::query()
            ->where('warmup_send_id', $send->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $sent = (int) ($byStatus[WarmupSendRecipient::STATUS_SENT] ?? 0);
        $failed = (int) ($byStatus[WarmupSendRecipient::STATUS_FAILED] ?? 0);
        $attempted = $sent + $failed;

        $queued = (int) $send->queued_count;
        $cancelled = $send->cancelled_at !== null;

        /*
         * Still running? Only while all three hold:
         *
         *   - it was not cancelled,
         *   - fewer attempts are recorded than were queued (or the fan-out has
         *     not written queued_count yet, which it does only when it finishes),
         *   - and it started recently enough that the run lock could still exist.
         *
         * The last clause is what stops a crashed fan-out from being reported as
         * in-flight for the rest of time.
         */
        $withinLockWindow = $send->created_at !== null
            && $send->created_at->gt(now()->subSeconds(self::RUN_LOCK_SECONDS));

        $inProgress = ! $cancelled
            && ($queued === 0 || $attempted < $queued)
            && $withinLockWindow;

        return [
            'id' => $send->id,
            // The run's own timestamp. The per-address `sent_at` values are
            // minutes later and differ between batches; this is the one
            // instant the whole run can be labelled with.
            'started_at' => $send->created_at?->toISOString(),
            'requested_count' => $send->requested_count,
            'queued_count' => $queued,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'attempted_count' => $attempted,
            'cooldown_days' => $send->cooldown_days,
            'template' => $send->template,
            'template_label' => $this->templates->label($send->template),
            'site_name' => $send->site?->name,
            // Who pressed Send. Null for a run whose admin account has since
            // been deleted — the audit outlives the account.
            'sent_by' => $send->user?->name,
            'in_progress' => $inProgress,
            'cancelled_at' => $send->cancelled_at?->toISOString(),
        ];
    }

    /**
     * The one site warmup sends as, from config('warmup.site_slug').
     *
     * Null when the configured slug names no ACTIVE site. Callers must treat that
     * as a hard stop rather than falling back: rendering another brand's template
     * would put the wrong branding in real inboxes, which is worse than not
     * sending at all.
     */
    private function site(): ?Site
    {
        // Memoised: audience() reads it twice, and it is a query.
        if ($this->siteResolved) {
            return $this->site;
        }

        $this->siteResolved = true;
        $slug = trim((string) config('warmup.site_slug', ''));

        if ($slug !== '') {
            $this->site = Site::query()->where('slug', $slug)->where('active', true)->first();
        }

        return $this->site;
    }

    private function defaultCooldownDays(): int
    {
        $default = (int) config('warmup.default_cooldown_days', WarmupSend::MIN_COOLDOWN_DAYS);

        return min(
            WarmupSend::MAX_COOLDOWN_DAYS,
            max(WarmupSend::MIN_COOLDOWN_DAYS, $default),
        );
    }

    /** Why a warmup send cannot start, in terms the operator can act on. */
    private function missingSiteMessage(): string
    {
        $slug = trim((string) config('warmup.site_slug', ''));

        return $slug === ''
            ? 'No warmup site is configured. Set WARMUP_SITE_SLUG in the environment.'
            : "Warmup is configured to send as \"{$slug}\", but no active site has that slug. "
                .'Register or reactivate it, or change WARMUP_SITE_SLUG.';
    }

    /** Plain-language summary of what the stop actually achieved. */
    private function cancelMessage(bool $lockFreed, bool $queuedWorkStopped, ?int $stoppedId): string
    {
        if (! $lockFreed) {
            return 'The run lock could not be cleared. Check that the cache store is reachable.';
        }

        if (! $queuedWorkStopped) {
            return 'The lock is cleared, so a new run can start. Batches already queued could not be '
                .'stopped — run `php artisan migrate` to enable that.';
        }

        return $stoppedId === null
            ? 'No run was in progress. The lock has been cleared, so a new run can start.'
            : "Run #{$stoppedId} stopped. Queued batches will not send, and a new run can start.";
    }
}
