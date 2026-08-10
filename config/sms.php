<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SMS (Twilio) — bulk phone-newsletter sending
|--------------------------------------------------------------------------
|
| Sizing and behaviour for the phone-newsletter bulk send. Literals, not env()
| lookups: these are correctness constraints tuned against the queue's
| retry_after, not per-environment preferences, and nothing here belongs in a
| production .env.
|
| Twilio CREDENTIALS deliberately do not live here. They are stored per
| configuration in the `twilio_configs` table and chosen by the admin at send
| time — the same model the promotion providers use, and for the same reason: an
| operator runs more than one Twilio account/sender and needs to pick.
|
*/

return [

    /*
    | Default country calling code, digits only and WITHOUT the "+", used to
    | complete numbers that arrive without one.
    |
    | Spreadsheets are full of local formats ("07700 900123", "(555) 010-0199").
    | Twilio requires E.164, so a number with no country code has to be either
    | completed or rejected. Null REJECTS — the safe default, because guessing
    | wrong sends a real message to a real stranger in another country.
    |
    | Set it only when the list is genuinely single-country, e.g. '44'.
    */
    'default_country_code' => null,

    /*
    | Whether a leading "0" is a national trunk prefix to be stripped when
    | default_country_code completes a number ("07700 900123" → "+447700900123").
    | True is correct for most of Europe; wrong for e.g. Italy. Only consulted
    | when default_country_code is set.
    */
    'strip_national_prefix' => true,

    /*
    | Seconds a queued phone-list import may run. Runtime scales with the file,
    | so this is generous — but it MUST stay below the queue connection's
    | `retry_after`, or a long import is handed to a second worker while the
    | first is still writing.
    */
    'import_timeout' => 900,

    // Numbers per queued send job. Sending is sequential and network-bound, so
    // this is the granularity of failure and retry.
    'send_batch_size' => 100,

    // Rows per database round-trip while streaming the audience. Decoupled from
    // send_batch_size so a large audience costs few queries while still
    // dispatching small, quickly-retryable jobs.
    'read_chunk' => 500,

    /*
    | Seconds the fan-out job may run while streaming the audience and queueing
    | batches. MUST stay below the queue connection's `retry_after`
    | (config/queue.php), or a slow fan-out is handed to a second worker and the
    | same numbers are queued twice.
    */
    'fan_out_timeout' => 900,

    /*
    | Seconds one send batch may run. Same retry_after constraint, and it matters
    | more here: a duplicate SMS costs money and cannot be recalled.
    */
    'batch_timeout' => 240,

    // Seconds to wait on a single Twilio API call. Low on purpose — one
    // unresponsive request must not eat the whole batch's timeout budget.
    'request_timeout' => 15,

    /*
    | How many history rows to buffer before writing them. Results are flushed
    | in groups rather than one INSERT per recipient; a small number keeps the
    | window of work-lost-on-crash short while still cutting write volume ~25x.
    */
    'history_flush_size' => 25,

    // Log a progress line every N numbers within a batch run.
    'progress_log_every' => 500,

    /*
    | Maximum characters accepted in a message body.
    |
    | 1600 is Twilio's hard ceiling for a concatenated message. Note that
    | anything over 160 GSM-7 characters (70 if the text contains emoji or other
    | non-GSM characters) is billed as multiple segments — the admin UI surfaces
    | the segment count so this is a deliberate choice rather than a surprise.
    */
    'max_body_length' => 1600,

];
