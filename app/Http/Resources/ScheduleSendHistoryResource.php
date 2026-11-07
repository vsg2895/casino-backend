<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of Email History → Schedules. */
class ScheduleSendHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'email_schedule_id' => $this->email_schedule_id,
            // The name at send time, so a renamed or deleted schedule still reads.
            'schedule_name'     => $this->schedule_name,
            'site_id'           => $this->site_id,
            'site_name'         => $this->whenLoaded('site', fn () => $this->site?->name),
            'audience'          => $this->audience,
            'audience_label'    => EmailSchedule::AUDIENCE_LABELS[$this->audience] ?? $this->audience,
            'email'             => $this->email,
            'template'          => $this->template,
            'provider'          => $this->provider,
            'credential_label'  => $this->credential_label,
            'from_address'      => $this->from_address,
            'status'            => $this->status,
            'error'             => $this->error,
            'sent_at'           => $this->sent_at,
            'sent_date'         => $this->sent_date?->toDateString(),
        ];
    }
}
