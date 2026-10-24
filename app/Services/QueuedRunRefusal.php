<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Why a queued bulk run did not start.
 *
 * The reason, not an HTTP status: the controller decides how each one is
 * answered ({@see \App\Http\Controllers\Concerns\RespondsWithQueuedRun}), so the
 * services stay usable outside a request.
 */
enum QueuedRunRefusal
{
    /** Nothing can be sent: no audience, no sender, or no site to send as. */
    case Unsendable;

    /** Another run holds the run lock. */
    case AlreadyRunning;

    /** The run row or the fan-out job could not be written; the lock was released. */
    case QueueFailed;
}
