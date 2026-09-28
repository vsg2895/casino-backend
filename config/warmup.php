<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Email warmup
|--------------------------------------------------------------------------
|
| Settings for the warmup list and its send. Literals, not env() lookups:
| nothing here belongs in .env, and the mailer in particular is a correctness
| constraint rather than a per-environment preference.
|
*/

return [

    /*
    | The mailer warmup ALWAYS sends through.
    |
    | Pinned to the .env SMTP transport on purpose, rather than following
    | config('mail.admin_mailer') like the admin test buttons do. Warmup exists
    | to build the reputation of THIS mailbox — the credentials in MAIL_HOST /
    | MAIL_USERNAME / MAIL_PASSWORD. Routing it through SendGrid or Mailgun
    | would warm those providers' shared infrastructure instead and quietly
    | defeat the entire feature, so flipping MAIL_ADMIN_MAILER must not drag
    | warmup along with it.
    |
    | Must name a mailer defined in config/mail.php.
    */
    'mailer' => 'smtp',

    /*
    |--------------------------------------------------------------------------
    | Sending rate — a ROLLING window, not a delay between messages
    |--------------------------------------------------------------------------
    |
    | The ceiling a warmup run may never exceed: at most `max_emails` messages
    | in ANY `window_seconds` stretch, counted across every worker.
    |
    | This is not the same thing as pausing between messages. A fixed delay
    | spaces sends out but bounds nothing — two workers each waiting six seconds
    | send twenty a minute between them, and a backlog released after a stall
    | goes out with no pause at all. A rolling window bounds the stretch itself,
    | which is what a receiving mail host actually measures.
    |
    | Env-overridable because the right number is a property of the mailbox being
    | warmed and of how far through its schedule it is — an operator setting, not
    | a code constant. Enforced by App\Services\Mail\WarmupRateLimiter.
    |
    | There is deliberately NO value that switches the limit off: zero or a
    | negative number falls back to the defaults below. The limiter exists to
    | protect a mailbox's reputation, and a typo in .env must not be able to
    | disable that quietly.
    |
    | RAISING max_emails costs nothing. LOWERING it makes each batch take longer
    | in wall-clock time, which `send_timeout` below is sized against — read the
    | note there before changing it.
    */
    'rate_limit' => [

        'max_emails' => (int) env('WARMUP_EMAILS_PER_MINUTE', 10),

        'window_seconds' => (int) env('WARMUP_RATE_LIMIT_WINDOW_SECONDS', 60),

    ],

    // Addresses per queued send job. Sending is sequential and network-bound,
    // so this is the granularity of failure and retry.
    'send_batch_size' => 100,

    // Rows per database round-trip while streaming the selection. Decoupled from
    // send_batch_size for the same reason the promotion sender decouples them: a
    // long list costs few queries while the dispatched jobs stay small and
    // quickly retryable.
    'read_chunk' => 500,

    // Seconds the fan-out may run while streaming addresses and queueing batches.
    // MUST stay below the queue connection's `retry_after` (config/queue.php), or
    // a slow fan-out is handed to a second worker and addresses are queued twice.
    'fan_out_timeout' => 900,

    /*
    | Seconds a single warmup batch may run.
    |
    | MUST stay below the queue connection's `retry_after` (config/queue.php), or
    | a slow batch gets handed to a second worker and the same addresses are
    | mailed twice.
    |
    | Since the rate limit above exists, this is no longer a ceiling on how long
    | a batch of `send_batch_size` addresses takes — at 10 per minute, 100
    | addresses need roughly ten minutes of wall clock, far past any safe
    | timeout. The batch job therefore treats this value as a TIME BUDGET: when
    | the budget is nearly spent it re-queues whatever addresses are left as
    | another batch of the same run and returns cleanly, instead of being killed
    | mid-flight and retried — which would re-send everything it had already
    | delivered. So this number controls how much a single job invocation gets
    | through, not how much the run gets through.
    */
    'send_timeout' => 240,

    /*
    | Attempts buffered in memory before being flushed to `warmup_send_recipients`.
    |
    | This is the crash window: if a worker is killed mid-batch, at most this many
    | delivered addresses lack a history row. Lower = safer, at one extra INSERT
    | per this many recipients. Matches the promotion pipeline's
    | `promotions.history_flush_size` so both audit trails behave the same way.
    */
    'history_flush_size' => 25,

    /*
    | Cooldown offered by default when the admin switches off "send to every
    | address". One day is the mildest useful setting: it only prevents mailing
    | the same seed address twice in one day, which no real mailbox does.
    |
    | The permitted RANGE is not configurable — it is a product rule and lives on
    | WarmupSend::MIN_COOLDOWN_DAYS / MAX_COOLDOWN_DAYS so the validator, the API
    | and the admin input all read one source.
    */
    'default_cooldown_days' => 1,

    /*
    | The ONE site warmup sends as.
    |
    | Warmup exists to build the reputation of a single sending mailbox, and the
    | operator warms it through one brand only — so this is pinned rather than
    | chosen per run. The admin shows it read-only and the API does not accept a
    | site at all, which means a stale admin bundle cannot send as a different
    | brand either.
    |
    | A slug rather than an id, so it survives a reseed (ids move, slugs do not),
    | and env-overridable so the choice stays an operator setting instead of a
    | brand name baked into the code. If it names no active site the send fails
    | loudly: rendering some other brand's template would put the wrong branding
    | in real inboxes, which is worse than not sending.
    */
    'site_slug' => env('WARMUP_SITE_SLUG', 'idevaffiliation'),

];
