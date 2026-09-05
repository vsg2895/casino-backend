<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendSmtpCredentialTestRequest;
use App\Http\Requests\Admin\StoreSmtpCredentialRequest;
use App\Http\Requests\Admin\UpdateSmtpCredentialRequest;
use App\Http\Requests\Admin\UpdateSmtpReceiverSettingsRequest;
use App\Http\Resources\MailgunReceiverResource;
use App\Http\Resources\SmtpCredentialResource;
use App\Jobs\SendSmtpReceiverCampaignJob;
use App\Mail\SmtpCredentialTestMessage;
use App\Models\SmtpCredential;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\MailgunReceiverSelector;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Throwable;

/**
 * Admin CRUD and campaign control for stored SMTP servers.
 *
 * Shaped after {@see MailgunKeyController}, against the SAME receiver list and
 * the SAME selector — the two channels differ only in how they authenticate.
 *
 * The one structural difference is that there is no scheduling here. A Mailgun
 * credential carries `send_enabled` because the scheduler decides when it runs;
 * these run only from {@see runReceiverCampaign()}, so no such flag exists and
 * nothing in this class reads one.
 */
class SmtpCredentialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SmtpCredential::query()->latest();

        if (in_array($request->query('status'), SmtpCredential::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return SmtpCredentialResource::collection($query->get());
    }

    public function store(StoreSmtpCredentialRequest $request): JsonResponse
    {
        $credential = SmtpCredential::create([
            'status' => SmtpCredential::STATUS_ACTIVE,
            ...$request->validated(),
        ]);

        return (new SmtpCredentialResource($credential))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateSmtpCredentialRequest $request,
        SmtpCredential $smtpCredential,
    ): SmtpCredentialResource {
        $validated = $request->validated();

        // A blank password field means "keep the stored one". The admin can
        // never read the current value, so requiring it on every edit would make
        // changing a port impossible without also retyping a secret.
        if (trim((string) ($validated['password'] ?? '')) === '') {
            unset($validated['password']);
        }

        $smtpCredential->update($validated);

        return new SmtpCredentialResource($smtpCredential);
    }

    /**
     * Delete a credential and its send history.
     *
     * Nothing else can reference one — there are no schedules on this channel —
     * so unlike {@see MailgunKeyController::destroy()} there is no in-use check
     * to make. `smtp_receiver_sends` cascades: history for a deleted credential
     * has no meaning, and the cross-channel daily claims are left alone because
     * they record that a person was mailed, not who mailed them.
     */
    public function destroy(SmtpCredential $smtpCredential): JsonResponse
    {
        $smtpCredential->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Flip active ⇄ inactive without touching the password. */
    public function toggle(SmtpCredential $smtpCredential): SmtpCredentialResource
    {
        $smtpCredential->update([
            'status' => $smtpCredential->isActive()
                ? SmtpCredential::STATUS_INACTIVE
                : SmtpCredential::STATUS_ACTIVE,
        ]);

        return new SmtpCredentialResource($smtpCredential);
    }

    /**
     * Send one test message through this credential.
     *
     * Goes through the real transport, not a simulated one — the whole point is
     * to find out whether these host/port/encryption settings authenticate. The
     * credential may be inactive: testing one you have just disabled, to find out
     * why it failed, has to work.
     */
    public function test(
        SendSmtpCredentialTestRequest $request,
        SmtpCredential $smtpCredential,
        PromotionMailerFactory $mailers,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $mailer = $mailers->mailerForSmtpCredential($smtpCredential);

            $mailer->to($validated['to'])->send(
                new SmtpCredentialTestMessage($smtpCredential, $validated['name'] ?? null),
            );
        } catch (Throwable $e) {
            // The transport's own message is the useful part — "Connection could
            // not be established", "535 authentication failed" — so it is passed
            // through rather than replaced with a generic failure.
            return response()->json([
                'ok'      => false,
                'message' => mb_strimwidth($e->getMessage(), 0, 500, '…'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Test message sent to ' . $validated['to'] . '.',
        ]);
    }

    // ── Receiver campaign ────────────────────────────────────────────────────

    public function receiverSettings(
        SmtpCredential $smtpCredential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        $seed = $this->templateSeed();

        return response()->json(['data' => [
            'batch_size'      => (int) $smtpCredential->batch_size,
            'selection_order' => (string) $smtpCredential->selection_order,
            'cooldown_days'   => $smtpCredential->cooldown_days === null
                ? null
                : (int) $smtpCredential->cooldown_days,
            'message_subject' => $smtpCredential->message_subject ?? $seed['subject'],
            // A credential that has never been configured opens on the source
            // site's promotion template rather than a blank form; one that HAS
            // been configured gets exactly what was saved, so the seed can never
            // overwrite authored copy.
            'message_template' => $smtpCredential->message_template === null
                ? $seed['template']
                : MailgunReceiverTemplate::merged($smtpCredential->message_template),
            'template_source'  => $seed['site_name'],
            'last_run_at'      => $smtpCredential->last_run_at,
            'eligible_count'   => $selector->eligible($smtpCredential),
            'next_batch_count' => $selector->batchCount($smtpCredential),
            'blocked_reason'   => SendSmtpReceiverCampaignJob::blockedReason($smtpCredential),
        ]]);
    }

    /**
     * Save the targeting rule and message for this credential.
     *
     * `message_html` is rendered here rather than accepted from the client: the
     * admin authors fields, the server owns the markup. That is what keeps
     * arbitrary HTML — pasted, malformed or hostile — out of a bulk send.
     */
    public function updateReceiverSettings(
        UpdateSmtpReceiverSettingsRequest $request,
        SmtpCredential $smtpCredential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        $validated = $request->validated();
        $template = MailgunReceiverTemplate::merged($validated['message_template'] ?? null);

        $smtpCredential->update([
            ...$validated,
            'message_template' => $template,
            // An empty template stores an empty body, so blockedReason() reports
            // "no message is configured" instead of the credential shipping a
            // bare shell with nothing but an unsubscribe link in it.
            'message_html'     => MailgunReceiverTemplate::isEmpty($template)
                ? null
                : MailgunReceiverTemplate::render($template),
        ]);

        return response()->json(['data' => [
            'eligible_count'   => $selector->eligible($smtpCredential),
            'next_batch_count' => $selector->batchCount($smtpCredential),
            'blocked_reason'   => SendSmtpReceiverCampaignJob::blockedReason($smtpCredential->fresh()),
        ]]);
    }

    /**
     * Re-seed the form from the source site's promotion template.
     *
     * Separate from {@see receiverSettings()} because that one must NEVER
     * overwrite authored copy, while this is the admin explicitly asking for it.
     * Returns the fields only — nothing is written until they save.
     */
    public function receiverTemplateSource(SmtpCredential $smtpCredential): JsonResponse
    {
        return response()->json(['data' => $this->templateSeed()]);
    }

    /**
     * Render the message as it would be sent, from unsaved fields.
     *
     * Goes through the same renderer and the same wrapper as a real send — the
     * unsubscribe block included — so what the admin approves in the preview is
     * what the list receives.
     */
    public function previewReceiverMessage(Request $request, SmtpCredential $smtpCredential): JsonResponse
    {
        $validated = $request->validate(MailgunReceiverTemplate::rules());
        $template = MailgunReceiverTemplate::merged($validated['message_template'] ?? null);

        $html = view('mail.mailgun-receiver-message', [
            'bodyHtml'        => MailgunReceiverTemplate::render($template),
            'unsubscribeUrl'  => '#',
            'backgroundColor' => $template['background_color'],
            'mutedColor'      => $template['muted_text_color'],
            'accentColor'     => $template['accent_color'],
        ])->render();

        return response()->json(['html' => $html]);
    }

    /**
     * The exact receivers the next run would take.
     *
     * Resolved through the shared selector, so this listing and the send cannot
     * disagree — and so it reports the same audience the Mailgun screen does.
     */
    public function previewReceiverBatch(
        SmtpCredential $smtpCredential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        return response()->json([
            'data' => MailgunReceiverResource::collection($selector->preview($smtpCredential, 100)),
            'meta' => [
                'eligible_count'   => $selector->eligible($smtpCredential),
                'next_batch_count' => $selector->batchCount($smtpCredential),
            ],
        ]);
    }

    /**
     * Queue one run for this credential now.
     *
     * The only way this channel ever sends. Refuses through the job's own
     * {@see SendSmtpReceiverCampaignJob::blockedReason()}, so the admin sees the
     * same wording the log records.
     */
    public function runReceiverCampaign(SmtpCredential $smtpCredential): JsonResponse
    {
        $reason = SendSmtpReceiverCampaignJob::blockedReason($smtpCredential);

        if ($reason !== null) {
            return response()->json(
                ['ok' => false, 'message' => ucfirst($reason) . '.'],
                Response::HTTP_CONFLICT,
            );
        }

        SendSmtpReceiverCampaignJob::dispatch($smtpCredential->id);

        return response()->json(['ok' => true, 'message' => 'Run queued.']);
    }

    /**
     * The promotion template the receiver form starts from.
     *
     * Falls back to a blank template when there is no site to read one from — a
     * fresh install, or every site deleted — so the modal still opens.
     *
     * @return array{subject: string, template: array<string, mixed>, site_name: string|null}
     */
    private function templateSeed(): array
    {
        return MailgunReceiverTemplate::seed();
    }
}
