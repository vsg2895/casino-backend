<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/**
 * The targeting rule and message for one SendGrid key's receiver campaign.
 *
 * The SMTP rules exactly: like an own SMTP server, a SendGrid key runs only
 * from "Run now", so there is no `send_enabled` flag and a half-configured key
 * saves as a draft. {@see \App\Jobs\SendSendgridReceiverCampaignJob::blockedReason()}
 * is what refuses to run one without a subject, a body or a From address.
 */
class UpdateSendgridReceiverSettingsRequest extends UpdateSmtpReceiverSettingsRequest {}
