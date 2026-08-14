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

    // Addresses per queued send job. Sending is sequential and network-bound,
    // so this is the granularity of failure and retry.
    'send_batch_size' => 100,

    // Rows per database round-trip while streaming the rotation. Decoupled from
    // send_batch_size for the same reason the promotion sender decouples them: a
    // long list costs few queries while the dispatched jobs stay small and
    // quickly retryable.
    'read_chunk' => 500,

    // Seconds the fan-out may run while streaming addresses and queueing batches.
    // MUST stay below the queue connection's `retry_after` (config/queue.php), or
    // a slow fan-out is handed to a second worker and addresses are queued twice.
    'fan_out_timeout' => 900,

    // Seconds a single warmup batch may run. MUST stay below the queue
    // connection's `retry_after` (config/queue.php), or a slow batch gets handed
    // to a second worker and the same addresses are mailed twice.
    'send_timeout' => 240,

];
