<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use App\Models\UniOne\UniOneReceiver;

/** Editing a UniOne receiver: its own address does not count as taken. */
class UpdateUniOneReceiverRequest extends StoreUniOneReceiverRequest
{
    protected function ignoredReceiverId(): ?int
    {
        $receiver = $this->route('uniOneReceiver');

        return $receiver instanceof UniOneReceiver ? (int) $receiver->id : null;
    }
}
