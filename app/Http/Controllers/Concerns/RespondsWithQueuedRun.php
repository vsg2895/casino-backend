<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Services\QueuedRunOutcome;
use App\Services\QueuedRunRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The one answer every refused bulk run gives: `{ok: false, message}`, with the
 * status chosen by why it was refused.
 */
trait RespondsWithQueuedRun
{
    protected function refusedRun(QueuedRunOutcome $outcome): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $outcome->message,
        ], match ($outcome->refusal) {
            QueuedRunRefusal::AlreadyRunning => Response::HTTP_CONFLICT,
            QueuedRunRefusal::QueueFailed => Response::HTTP_INTERNAL_SERVER_ERROR,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        });
    }
}
