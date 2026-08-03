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

];
