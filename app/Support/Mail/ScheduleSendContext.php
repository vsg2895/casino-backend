<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\EmailSchedule;
use App\Models\ScheduleSendHistory;

/**
 * What a schedule batch writes on every history row: which schedule, which
 * list, and which email config carried the message.
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
            'template'          => EmailSchedule::TEMPLATE_PROMOTION,
            'provider'          => $this->provider,
            'credential_id'     => $this->credentialId,
            'credential_label'  => $this->credentialLabel,
            'from_address'      => $this->fromAddress,
            'status'            => $status,
            'error'             => $error,
        ];
    }
}
