<?php

declare(strict_types=1);

namespace App\Http\Resources\UniOne;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UniOne\UniOneSend */
class UniOneSendResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => (int) $this->id,
            'subject'         => $this->subject,
            'from_email'      => $this->from_email,
            'from_name'       => $this->from_name,
            'reply_to'        => $this->reply_to,
            'status'          => $this->status,
            'requested_count' => (int) $this->requested_count,
            'eligible_count'  => (int) $this->eligible_count,
            'accepted_count'  => (int) $this->accepted_count,
            'failed_count'    => (int) $this->failed_count,
            'chunk_count'     => (int) $this->chunk_count,
            'cooldown_hours'  => (int) $this->cooldown_hours,
            'error'           => $this->error,
            'completed_at'    => $this->completed_at?->toISOString(),
            'created_at'      => $this->created_at?->toISOString(),
            'key'             => $this->whenLoaded('key', fn (): array => [
                'id'       => (int) $this->key->id,
                'name'     => $this->key->name,
                'key_type' => $this->key->key_type,
                'region'   => $this->key->region,
            ]),
            // Per-chunk detail: job_id, HTTP status, API error code, latency —
            // what the brief's log row needs to explain a failed delivery.
            'chunks'          => $this->whenLoaded('chunks', fn (): array => $this->chunks->map(fn ($c): array => [
                'chunk_index'     => (int) $c->chunk_index,
                'job_id'          => $c->job_id,
                'status'          => $c->status,
                'recipient_count' => (int) $c->recipient_count,
                'accepted_count'  => (int) $c->accepted_count,
                'failed_count'    => (int) $c->failed_count,
                'http_status'     => $c->http_status,
                'api_error_code'  => $c->api_error_code,
                'latency_ms'      => $c->latency_ms,
                'attempts'        => (int) $c->attempts,
                'error'           => $c->error,
                'committed_at'    => $c->committed_at?->toISOString(),
            ])->all()),
        ];
    }
}
