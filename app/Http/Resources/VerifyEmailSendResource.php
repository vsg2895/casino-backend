<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VerifyEmailSend;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VerifyEmailSend */
class VerifyEmailSendResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'email' => $this->email,

            // Null once the subscriber has been deleted. `email` above is the
            // denormalised copy that keeps the row readable when they are —
            // which is the whole reason the FK nulls rather than cascades.
            'newsletter_id' => $this->newsletter_id,

            'status' => $this->status,
            'error'  => $this->error,
            // A "Send test" from the Verify Email screen rather than a real
            // subscriber's confirmation. Both belong here; only one is evidence
            // that somebody was asked to verify.
            'is_test' => (bool) $this->is_test,

            // Only present when eager-loaded; this stream renders one site's
            // template, so which site it was is part of the record.
            'site' => $this->whenLoaded(
                'site',
                fn (): ?array => $this->site === null ? null : [
                    'id'     => $this->site->id,
                    'name'   => $this->site->name,
                    'domain' => $this->site->domain,
                ],
            ),
            'site_id' => $this->site_id,

            // When the attempt happened. `created_at` is when the row was
            // written, which is the same instant here — kept for symmetry with
            // the warmup history, where the two can differ by a buffer flush.
            'sent_at'    => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }
}
