<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\EmailSchedule;
use App\Models\ScheduleSendHistory;

/**
 * What a schedule batch writes on every history row: which schedule, which
 * list, which TEMPLATE, and which email config carried the message.
 *
 * Resolved ONCE per batch — the credential's name is one query — and stamped
 * onto each row as plain text, so the history stays readable after a schedule
 * or a key is renamed or deleted.
 */
final readonly class ScheduleSendContext
{
    public function __construct(
        public ?int $scheduleId,
        public ?string $scheduleName,
        public int $siteId,
        public string $audience,
        public string $provider,
        public ?int $credentialId,
        public string $credentialLabel,
        public ?string $fromAddress,
        /**
         * WHICH email went out. Defaulted to the promotion template so a
         * caller written before schedules could choose one — and an in-flight
         * job from before this deploy — records exactly what it always did.
         *
         * It is also the key the cooldown rests an address against, so a site
         * running a promotion schedule and a custom one does not have the
         * first silently suppress the second.
         */
        public string $template = EmailSchedule::TEMPLATE_PROMOTION,
    ) {}

    public static function for(
        ?EmailSchedule $schedule,
        int $scheduleId,
        int $siteId,
        string $audience,
        string $provider,
        ?int $credentialId,
        ?string $fromAddress,
    ): self {
        return new self(
            $scheduleId,
            $schedule?->name !== null && $schedule->name !== '' ? mb_substr((string) $schedule->name, 0, 120) : null,
            $siteId,
            $audience,
            $provider,
            $credentialId,
            mb_substr(EmailSchedule::credentialLabel($provider, $credentialId), 0, 160),
            $fromAddress,
            // A batch whose schedule row has since been deleted still records
            // the promotion template, which is what it was sending.
            $schedule?->templateKey() ?? EmailSchedule::TEMPLATE_PROMOTION,
        );
    }

    /**
     * One row for {@see ScheduleSendHistory::record()}. Every row carries every
     * column, because a multi-row upsert needs one shape.
     *
     * @return array<string, mixed>
     */
    public function row(string $email, ?int $recipientId, string $status, ?string $error = null): array
    {
        return [
            'email_schedule_id' => $this->scheduleId,
            'schedule_name'     => $this->scheduleName,
            'site_id'           => $this->siteId,
            'audience'          => $this->audience,
            'recipient_id'      => $recipientId,
            'email'             => $email,
            'template'          => $this->template,
            'provider'          => $this->provider,
            'credential_id'     => $this->credentialId,
            'credential_label'  => $this->credentialLabel,
            'from_address'      => $this->fromAddress,
            'status'            => $status,
            'error'             => $error,
        ];
    }
}
