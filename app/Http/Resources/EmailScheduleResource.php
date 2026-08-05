<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailSchedule */
class EmailScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'site_id'       => $this->site_id,
            'site'          => new SiteResource($this->whenLoaded('site')),
            'name'          => $this->name,
            'date_filter'   => $this->date_filter,
            'specific_date' => $this->specific_date?->format('Y-m-d'),
            'limit'         => $this->limit,
            'frequency'     => $this->frequency,
            'time'          => substr((string) $this->time, 0, 5),
            'day_of_week'   => $this->day_of_week,
            'day_of_month'  => $this->day_of_month,
            'provider'        => $this->provider,
            'sendgrid_key_id' => $this->sendgrid_key_id,
            'sendgrid_key'    => new SendgridKeyResource($this->whenLoaded('sendgridKey')),
            'mailgun_key_id'  => $this->mailgun_key_id,
            'mailgun_key'     => new MailgunKeyResource($this->whenLoaded('mailgunKey')),
            'active'        => $this->active,
            'last_run_at'   => $this->last_run_at,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
