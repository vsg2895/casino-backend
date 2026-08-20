<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Promotion campaign throughput
|--------------------------------------------------------------------------
|
| Tunables for the scheduled promotion pipeline (fan-out job → batch jobs).
| They exist as config rather than constants because the right values depend
| on the transport: an SMTP relay that takes ~1s per message and a SendGrid
| API key that takes ~50ms want very different batch sizes.
|
| HARD RULE — the queue connection's `retry_after` MUST be greater than
| `fan_out_timeout`. If a job is still running when `retry_after` elapses the
| queue hands the SAME job to a second worker, which is how duplicate sends
| happen. See config/queue.php and the .env sample in the deploy notes.
|
*/

return [

    // Addresses per SendPromotionBatchJob. Each batch is one queue job and one
    // history INSERT, and is retried as a unit, so this is the granularity of
    // failure. Rough guide: batch_size × seconds-per-send must stay well below
    // `batch_timeout` (100 × 1s SMTP ≈ 100s, inside the 240s default).
    'batch_size' => (int) env('PROMOTION_BATCH_SIZE', 100),

    // Rows the fan-out pulls from the database per round-trip. Decoupled from
    // batch_size so a 50k audience costs ~100 queries instead of ~500; each
    // read is sliced into batch_size payloads before dispatch.
    'read_chunk' => (int) env('PROMOTION_READ_CHUNK', 500),

    // Attempts buffered in memory before being flushed to the history table.
    // This is the crash window: if a worker is killed mid-batch, at most this
    // many delivered addresses lack a history row and could be re-sent by the
    // retry. Lower = safer, at one extra INSERT per this many recipients.
    'history_flush_size' => (int) env('PROMOTION_HISTORY_FLUSH', 25),

    // Seconds the fan-out job may run. It streams the whole audience and
    // dispatches every batch, so at 50k recipients it needs far more than the
    // 60s worker default.
    'fan_out_timeout' => (int) env('PROMOTION_FAN_OUT_TIMEOUT', 900),

    // Seconds a single batch job may run — must exceed batch_size × the
    // slowest realistic per-message time, and stay under `retry_after`.
    'batch_timeout' => (int) env('PROMOTION_BATCH_TIMEOUT', 240),

    // Log one progress line per this many successful sends, counted across all
    // the batch jobs of a site's campaign. Set to 0 to turn the milestones off.
    'progress_log_every' => (int) env('PROMOTION_PROGRESS_LOG_EVERY', 500),

    // Tolerance subtracted from the 24h "already delivered" window, in minutes.
    // MUST exceed how long a campaign takes to send end to end: a run that
    // outlasts this value leaves the next day's run looking at deliveries still
    // inside the window, and it skips its entire audience. 180 covers a ~3h
    // campaign. Clamped to < 1440 (24h), beyond which nothing could ever be
    // deduped.
    //
    // Deliberately a literal, not an env() lookup: this is a correctness
    // constraint tied to how long a send takes, not a per-environment setting.
    // Change it here and deploy if a campaign ever runs longer than 3 hours.
    'dedup_jitter_minutes' => 180,

    // Most subscribers the post-verification sweep queues per run (it runs every
    // minute). A ceiling rather than a target: it caps the burst when a large
    // backlog becomes eligible at once — e.g. the first run after the feature is
    // switched on, when every already-verified subscriber qualifies. The
    // remainder is picked up by the following runs.
    'verification_dispatch_limit' => (int) env('PROMOTION_VERIFICATION_DISPATCH_LIMIT', 1000),

];
