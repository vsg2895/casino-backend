<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One validation attempt, for the admin log table and its row detail.
 *
 * The email IS exposed here — this screen exists to answer "why was this address
 * refused", which is unanswerable without it, and it is behind Sanctum admin
 * auth. Nothing else personal is in the table to expose.
 *
 * @mixin \App\Models\EmailValidationLog
 */
class EmailValidationLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'site_id'    => $this->site_id,
            'site_name'  => $this->whenLoaded('site', fn () => $this->site?->name),
            'site_slug'  => $this->whenLoaded('site', fn () => $this->site?->slug),
            'email'      => $this->email,
            'verdict'    => $this->verdict,
            'score'      => $this->score !== null ? (float) $this->score : null,
            // The full parsed tree, for the detail panel: syntax, MX/A record,
            // disposable, role address, known/suspected bounces.
            'suggestion' => $this->suggestion,
            'source'     => $this->source,
            'outcome'    => $this->outcome,
            // The rule that fired, machine-readable so the operator can filter
            // and group by it rather than parsing a sentence.
            'reason_code' => $this->reason_code,

            // The six checks as first-class fields, matching the real columns
            // the filters and aggregates run against.
            'has_valid_address_syntax'        => $this->has_valid_address_syntax,
            'has_mx_or_a_record'              => $this->has_mx_or_a_record,
            'is_suspected_disposable_address' => $this->is_suspected_disposable_address,
            'is_suspected_role_address'       => $this->is_suspected_role_address,
            'has_known_bounces'               => $this->has_known_bounces,
            'has_suspected_bounces'           => $this->has_suspected_bounces,

            // Kept whole for the collapsible raw block in the row detail.
            'raw_checks' => $this->raw_checks,
            'was_cached' => (bool) $this->was_cached,
            'http_status'   => $this->http_status,
            'error_message' => $this->error_message,
            'latency_ms'    => $this->latency_ms,
            'quota_month'   => $this->quota_month,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
