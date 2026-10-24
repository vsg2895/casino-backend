<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\MailgunReceiverResource;
use App\Models\MailgunKey;
use App\Models\SmtpCredential;
use App\Services\MailgunReceiverSelector;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Http\JsonResponse;

/**
 * The receiver-campaign endpoints shared by Mailgun keys and own SMTP servers.
 *
 * Both channels mail the SAME receiver list through the same selector,
 * template and renderer, so their settings, preview and template-source
 * endpoints were line-for-line copies. They differ in two places only, and both
 * are supplied by the calling controller rather than branched on here:
 *
 *  - the job whose `blockedReason()` gates a run, passed as a callable;
 *  - channel-only settings keys (Mailgun's `send_enabled`), rendered first.
 *
 * Running a campaign is NOT shared: the SMTP channel adds a pre-flight check
 * and a different confirmation, so each controller keeps its own.
 */
trait ManagesReceiverCampaign
{
    /**
     * @param  callable(MailgunKey|SmtpCredential): ?string  $blockedReason
     * @param  array<string, mixed>  $leading  Channel-only keys, rendered before the shared ones.
     */
    protected function receiverSettingsResponse(
        MailgunKey|SmtpCredential $credential,
        MailgunReceiverSelector $selector,
        callable $blockedReason,
        array $leading = [],
    ): JsonResponse {
        $seed = MailgunReceiverTemplate::seed();

        return response()->json(['data' => [
            ...$leading,
            'batch_size' => (int) $credential->batch_size,
            'selection_order' => (string) $credential->selection_order,
            'cooldown_days' => $credential->cooldown_days === null ? null : (int) $credential->cooldown_days,
            // Which source lists this credential may mail; [] means all of them.
            'receiver_sources' => $credential->campaignSourceLabels(),
            'message_subject' => $credential->message_subject ?? $seed['subject'],
            // The authored fields. A credential that has never been configured
            // opens on the source site's promotion template rather than a blank
            // form; one that HAS been configured gets exactly what was saved, so
            // the seed can never overwrite authored copy. `message_html` is the
            // rendered output and is NOT returned — nothing in the admin edits
            // it directly any more.
            'message_template' => $credential->message_template === null
                ? $seed['template']
                : MailgunReceiverTemplate::merged($credential->message_template),
            // Named so the modal can say where the starting copy came from.
            'template_source' => $seed['site_name'],
            'last_run_at' => $credential->last_run_at,
            'eligible_count' => $selector->eligible($credential),
            'next_batch_count' => $selector->batchCount($credential),
            'blocked_reason' => $blockedReason($credential),
        ]]);
    }

    /**
     * Save the targeting rule and message, and report the resulting state.
     *
     * `message_html` is rendered here rather than accepted from the client: the
     * admin authors fields, the server owns the markup. That is what keeps the
     * layout consistent across credentials and keeps arbitrary HTML — pasted,
     * malformed or hostile — out of a bulk send.
     *
     * @param  array<string, mixed>  $validated
     * @param  callable(MailgunKey|SmtpCredential): ?string  $blockedReason
     */
    protected function saveReceiverSettings(
        array $validated,
        MailgunKey|SmtpCredential $credential,
        MailgunReceiverSelector $selector,
        callable $blockedReason,
    ): JsonResponse {
        $template = MailgunReceiverTemplate::merged($validated['message_template'] ?? null);

        $credential->update([
            ...$validated,
            'message_template' => $template,
            // An empty template stores an empty body, so blockedReason() reports
            // "no message is configured" instead of the credential shipping a
            // bare shell with nothing but an unsubscribe link in it.
            'message_html' => MailgunReceiverTemplate::isEmpty($template)
                ? null
                : MailgunReceiverTemplate::render($template),
        ]);

        return response()->json(['data' => [
            'eligible_count' => $selector->eligible($credential),
            'next_batch_count' => $selector->batchCount($credential),
            'blocked_reason' => $blockedReason($credential->fresh()),
        ]]);
    }

    /**
     * The promotion template the receiver form starts from, re-seeded on request.
     *
     * Separate from the settings read because that one must NEVER overwrite
     * authored copy, while this is the admin explicitly asking for it. Falls
     * back to a blank template when there is no site to read one from — a fresh
     * install, or every site deleted — so the modal still opens.
     */
    protected function receiverTemplateSourceResponse(): JsonResponse
    {
        return response()->json(['data' => MailgunReceiverTemplate::seed()]);
    }

    /**
     * Render the message as it would be sent, from unsaved fields.
     *
     * Goes through the same renderer and the same wrapper as a real send — the
     * unsubscribe block included — so what the admin approves in the preview is
     * what the list receives. Only the unsubscribe destination differs: a preview
     * has no receiver, so there is no token to address.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function receiverMessagePreviewResponse(array $validated): JsonResponse
    {
        $template = MailgunReceiverTemplate::merged($validated['message_template'] ?? null);

        $html = view('mail.mailgun-receiver-message', [
            'bodyHtml' => MailgunReceiverTemplate::render($template),
            'unsubscribeUrl' => '#',
            'backgroundColor' => $template['background_color'],
            'mutedColor' => $template['muted_text_color'],
            'accentColor' => $template['accent_color'],
            'unsubscribeLabel' => $template['unsubscribe_label'],
        ])->render();

        return response()->json(['html' => $html]);
    }

    /**
     * The exact receivers the next run would take.
     *
     * Resolved through the shared selector, so this listing and the send cannot
     * disagree — and both channels report the same audience.
     */
    protected function receiverBatchPreviewResponse(
        MailgunKey|SmtpCredential $credential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        return response()->json([
            'data' => MailgunReceiverResource::collection($selector->preview($credential, 100)),
            'meta' => [
                'eligible_count' => $selector->eligible($credential),
                'next_batch_count' => $selector->batchCount($credential),
            ],
        ]);
    }
}
