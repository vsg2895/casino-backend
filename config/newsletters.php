<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Newsletter list imports
|--------------------------------------------------------------------------
|
| The upload endpoint stages the file and queues ImportNewslettersJob on the
| `high` queue; these knobs govern that job.
|
| HARD RULE — `import_timeout` MUST stay below the queue connection's
| `retry_after` (config/queue.php). If the job is still running when
| `retry_after` elapses, the queue hands it to a second worker.
|
*/

return [

    // Seconds a single import job may run. Runtime scales with the row count;
    // the batched importer handles ~50k rows in seconds, so this is headroom
    // for very large files rather than an expected duration.
    'import_timeout' => (int) env('NEWSLETTER_IMPORT_TIMEOUT', 900),

    /*
    |----------------------------------------------------------------------
    | Receiver campaign pacing
    |----------------------------------------------------------------------
    |
    | Messages per minute for a receiver-list campaign run. PACING ONLY: it
    | changes nothing about who is selected, what is sent, or how failures are
    | handled — the sender simply waits `60 / per_minute` seconds between
    | messages.
    |
    | A hundred messages in two seconds from a young sending domain reads as a
    | burst, and bursts are what trip rate limiting and spam folders. The run is
    | queued, so spreading the same volume across the minute costs nothing.
    |
    | 0 or less disables the wait.
    |
    */
    'receiver_campaign_per_minute' => (int) env('RECEIVER_CAMPAIGN_PER_MINUTE', 15),

];
