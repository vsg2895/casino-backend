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
            // WHICH email this schedule sends, and the Custom HTML fields —
            // null for every stored-template schedule, because the request
            // clears them when the template changes.
            'template'            => $this->templateKey(),
            'template_label'      => $this->templateLabel(),
            'custom_from_name'    => $this->custom_from_name,
            'custom_from_address' => $this->custom_from_address,
            'custom_subject'      => $this->custom_subject,
            'custom_html'         => $this->custom_html,
            // Which list, how many per run, and the rest period. The retired
            // date_filter / specific_date columns are not exposed.
            'audience'      => $this->audienceKey(),
            'limit'         => $this->limit,
            'cooldown_days' => $this->cooldownDays(),
            // Off for every schedule unless an admin deliberately turned it on.
            'include_unsubscribed' => $this->includesUnsubscribed(),
            // Non-null when the schedule cannot send (a legacy row with no count).
            //
            // Deliberately NOT extended with templateBlocker(): that one asks
            // whether the site's template is switched on, and the …OrDefault()
            // behind it is a firstOrCreate — one query, and possibly one INSERT,
            // per row of a 50-row listing. It is answered where it is actually
            // needed instead, by the run action, which deals in one schedule.
            'blocked_reason' => $this->runBlocker(),
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
