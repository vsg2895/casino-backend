<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Why {@see WarmupRunService::start()} did not queue a run.
 *
 * The reason, not an HTTP status: the controller decides how each one is
 * answered, so the service stays usable outside a request.
 */
enum WarmupRunRefusal
{
    /** The list is empty, no warmup site resolves, or the cooldown leaves nobody. */
    case NothingToSend;

    /** Another run holds the run lock. */
    case AlreadyRunning;

    /** The run row or the fan-out job could not be written; the lock was released. */
    case QueueFailed;
}
