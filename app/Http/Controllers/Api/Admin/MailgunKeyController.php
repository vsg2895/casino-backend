<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendMailgunKeyTestRequest;
use App\Http\Requests\Admin\StoreMailgunKeyRequest;
use App\Http\Requests\Admin\UpdateMailgunKeyRequest;
use App\Http\Requests\Admin\UpdateMailgunReceiverSettingsRequest;
use App\Http\Resources\MailgunKeyResource;
use App\Http\Resources\MailgunReceiverResource;
use App\Jobs\SendMailgunReceiverCampaignJob;
use App\Mail\MailgunCredentialTestMail;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\MailgunKey;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Services\Mail\EmailTemplateCatalog;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\MailgunReceiverSelector;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin CRUD for stored Mailgun credentials used by scheduled promotion sends.
 *
 * The raw key is write-only: accepted on create/update, never returned (the
 * Resource exposes only a masked preview).
 *
 * A credential still referenced by a schedule or by the Promotion After
 * Verification settings CANNOT be deleted — see {@see destroy()}. The foreign
 * keys are nullOnDelete, so the delete would otherwise succeed and leave the
 * referencing row silently pointing at nothing until its next run failed.
 *
 * Credentials may also carry a sender identity (from_address / from_name),
 * recorded for reference only — no send path reads it. Every send takes its
 * sender from the site template's own from_email.
 */
class MailgunKeyController extends Controller
{
    /**
     * This screen's own connection-test template.
     *
     * Not an EmailTemplateCatalog key on purpose: that catalog is shared with
     * the SendGrid test dialog and the warmup template picker, and this option
     * is specific to Mailgun credentials. Keeping it here leaves both of those
     * untouched.
     */
    public const string TEMPLATE_CONNECTION_TEST = 'mailgun_connection_test';

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MailgunKey::query()->latest();

        // Optional ?status=active filter (used by the schedule dropdown).
        if (in_array($request->query('status'), MailgunKey::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return MailgunKeyResource::collection($query->get());
    }

    public function store(StoreMailgunKeyRequest $request): JsonResponse
    {
        $key = MailgunKey::create([
            'status' => MailgunKey::STATUS_ACTIVE,
            ...$request->validated(),
        ]);

        return (new MailgunKeyResource($key))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateMailgunKeyRequest $request, MailgunKey $mailgunKey): MailgunKeyResource
    {
        // api_key is only present when the admin actually typed a new one, so a
        // blank edit preserves the stored key (see the Form Request).
        $mailgunKey->update($request->validated());

        return new MailgunKeyResource($mailgunKey);
    }

    /**
     * Delete a credential, unless something still sends through it.
     *
     * The foreign keys are nullOnDelete, so a delete would SUCCEED and quietly
     * leave the referencing schedule pointing at nothing — it would then fail at
     * its next run with "credential missing", long after the admin who deleted
     * it had moved on. Refusing up front, and naming what still uses it, turns a
     * delayed silent breakage into an immediate answerable error.
     */
    public function destroy(MailgunKey $mailgunKey): JsonResponse
    {
        $blockers = $this->referencesTo($mailgunKey);

        if ($blockers !== []) {
            return response()->json([
                'message' => 'This credential is still in use by ' . implode(' and ', $blockers)
                    . '. Point those at another credential first.',
            ], Response::HTTP_CONFLICT);
        }

        $mailgunKey->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Human-readable list of what still references this credential.
     *
     * @return list<string>
     */
    private function referencesTo(MailgunKey $mailgunKey): array
    {
        $blockers = [];

        $schedules = EmailSchedule::query()->where('mailgun_key_id', $mailgunKey->id)->pluck('name');
        if ($schedules->isNotEmpty()) {
            $blockers[] = 'schedule "' . $schedules->implode('", "') . '"';
        }

        $verification = VerificationPromotionEmail::query()
            ->where('mailgun_key_id', $mailgunKey->id)
            ->exists();
        if ($verification) {
            $blockers[] = 'the Promotion After Verification settings';
        }

        return $blockers;
    }

    /**
     * Send a REAL site email template THROUGH this stored key to prove it works.
     *
     * The admin picks the template (see {@see EmailTemplateCatalog}); the site
     * is resolved below rather than chosen, so the test exercises the exact
     * content a live send would produce — not placeholder text.
     *
     * Unlike the per-site test buttons (which always use the .env SMTP mailer,
     * see {@see \App\Http\Controllers\Concerns\SendsAdminTestEmail}), this one
     * must authenticate with the key under test — that is the whole point — so
     * it builds the per-credential Mailgun transport instead.
     *
     * Runs synchronously and surfaces the transport's real message on failure
     * (e.g. Mailgun's 401 for a revoked key), so the admin gets a definitive
     * works / does-not-work answer. Inactive keys can be tested too: verifying
     * a key before enabling it is a legitimate workflow.
     */
    public function test(
        SendMailgunKeyTestRequest $request,
        MailgunKey $mailgunKey,
        PromotionMailerFactory $mailers,
        EmailTemplateCatalog $catalog,
    ): JsonResponse {
        $to = (string) $request->validated('to');
        $type = (string) $request->validated('template');

        // The connection test renders no site content, so it short-circuits
        // everything below: no site to resolve, no subscriber to register.
        if ($type === self::TEMPLATE_CONNECTION_TEST) {
            return $this->sendConnectionTest($mailgunKey, $to, $mailers);
        }

        // No website picker in this dialog. Every template in the catalog
        // renders a specific site's content, so one still has to be chosen —
        // the first ACTIVE site, ordered by id so the choice is stable between
        // runs rather than whatever the database happened to return first.
        // The success message names the site that was used, so the admin is
        // never left guessing which content they received.
        $site = Site::query()->where('active', true)->orderBy('id')->first()
            ?? Site::query()->orderBy('id')->first();

        if ($site === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'No website is registered yet, so there is no template content to render.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Register the recipient against the selected site so the rendered
        // template carries that subscriber's REAL per-stream tokens — the
        // unsubscribe / verify links in the test are therefore live ones.
        // Identical to the per-site test buttons.
        $newsletter = Newsletter::firstOrCreate(['site_id' => $site->id, 'email' => $to]);
        // In memory only, so testing never overwrites a real subscriber's name.
        $newsletter->full_name = $request->validated('name');

        try {
            // NO From override: the test must go out from the SELECTED
            // template's own from_email (per site), so what the admin receives
            // matches a real send exactly. Overriding it here would have shown
            // the platform's shared verified sender instead of the site's.
            // If Mailgun rejects that sender as unverified, the 502 below
            // reports it — which is itself a useful result of the test.
            $mailable = $catalog->build($type, $site, $newsletter);
            $mailers->mailerForMailgunKey($mailgunKey)->to($to)->send($mailable);
        } catch (Throwable $e) {
            Log::warning('Mailgun key test failed', [
                'mailgun_key_id' => $mailgunKey->id,
                'site_id'         => $site->id,
                'template'        => $type,
                'to'              => $to,
                'error'           => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Key test failed: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'ok'      => true,
            'message' => sprintf(
                '%s template for %s sent to %s using "%s".',
                $catalog->label($type),
                $site->name,
                $to,
                $mailgunKey->name,
            ),
        ]);
    }

    /**
     * Send the plain connection test through this credential.
     *
     * Nothing site-specific is touched, so a failure here is unambiguous: the
     * credential, its domain, its region or its sender. The credential's own
     * from_address is used when it has one — there is no site template behind
     * this message to inherit a sender from.
     */
    private function sendConnectionTest(
        MailgunKey $mailgunKey,
        string $to,
        PromotionMailerFactory $mailers,
    ): JsonResponse {
        try {
            $mailers->mailerForMailgunKey($mailgunKey)->to($to)->send(
                new MailgunCredentialTestMail(
                    (string) $mailgunKey->name,
                    (string) $mailgunKey->domain,
                    (string) $mailgunKey->region,
                    $mailgunKey->from_address,
                    $mailgunKey->from_name,
                ),
            );
        } catch (Throwable $e) {
            Log::warning('Mailgun connection test failed', [
                'mailgun_key_id' => $mailgunKey->id,
                'to'             => $to,
                'error'          => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'ok'      => true,
            'message' => sprintf('Connection test sent to %s using "%s".', $to, $mailgunKey->name),
        ]);
    }

    /**
     * Read this credential's receiver targeting, with a LIVE eligible count.
     *
     * The count comes from MailgunReceiverSelector — the same object the sending
     * job uses — so the number in the modal is the number that will be mailed.
     */
    public function receiverSettings(MailgunKey $mailgunKey, MailgunReceiverSelector $selector): JsonResponse
    {
        $seed = $this->templateSeed();

        return response()->json(['data' => [
            'send_enabled'    => (bool) $mailgunKey->send_enabled,
            'batch_size'      => (int) $mailgunKey->batch_size,
            'selection_order' => (string) $mailgunKey->selection_order,
            'cooldown_days'   => $mailgunKey->cooldown_days === null ? null : (int) $mailgunKey->cooldown_days,
            'message_subject' => $mailgunKey->message_subject ?? $seed['subject'],
            // The authored fields. A credential that has never been configured
            // opens on the source site's promotion template rather than a blank
            // form; one that HAS been configured gets exactly what was saved, so
            // the seed can never overwrite authored copy. `message_html` is the
            // rendered output and is NOT returned — nothing in the admin edits
            // it directly any more.
            'message_template' => $mailgunKey->message_template === null
                ? $seed['template']
                : MailgunReceiverTemplate::merged($mailgunKey->message_template),
            // Named so the modal can say where the starting copy came from.
            'template_source' => $seed['site_name'],
            'last_run_at'     => $mailgunKey->last_run_at,
            'eligible_count'  => $selector->eligible($mailgunKey),
            'next_batch_count' => $selector->batchCount($mailgunKey),
            'blocked_reason'  => SendMailgunReceiverCampaignJob::blockedReason($mailgunKey),
        ]]);
    }

    /**
     * Save the targeting rule and message for this credential.
     *
     * `message_html` is rendered here rather than accepted from the client: the
     * admin authors fields, the server owns the markup. That is what keeps the
     * layout consistent across credentials and keeps arbitrary HTML — pasted,
     * malformed or hostile — out of a 100k-recipient send.
     */
    public function updateReceiverSettings(
        UpdateMailgunReceiverSettingsRequest $request,
        MailgunKey $mailgunKey,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        $validated = $request->validated();
        $template = MailgunReceiverTemplate::merged($validated['message_template'] ?? null);

        $mailgunKey->update([
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
            'eligible_count'   => $selector->eligible($mailgunKey),
            'next_batch_count' => $selector->batchCount($mailgunKey),
            'blocked_reason'   => SendMailgunReceiverCampaignJob::blockedReason($mailgunKey->fresh()),
        ]]);
    }

    /**
     * Re-seed the form from the source site's promotion template.
     *
     * Separate from {@see receiverSettings()} because that one must NEVER
     * overwrite authored copy, while this is the admin explicitly asking for it.
     * Returns the fields only — nothing is written until they save.
     */
    public function receiverTemplateSource(MailgunKey $mailgunKey): JsonResponse
    {
        return response()->json(['data' => $this->templateSeed()]);
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
        $site = MailgunReceiverTemplate::sourceSite();

        if ($site === null) {
            return [
                'subject'   => '',
                'template'  => MailgunReceiverTemplate::defaults(),
                'site_name' => null,
            ];
        }

        return MailgunReceiverTemplate::fromSite($site);
    }

    /**
     * Render the message as it would be sent, from unsaved fields.
     *
     * Goes through the same renderer and the same wrapper as a real send — the
     * unsubscribe block included — so what the admin approves in the preview is
     * what the list receives. Only the unsubscribe destination differs: a preview
     * has no receiver, so there is no token to address.
     */
    public function previewReceiverMessage(Request $request, MailgunKey $mailgunKey): JsonResponse
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
     * disagree — the guarantee the brief asks for.
     */
    public function previewReceiverBatch(MailgunKey $mailgunKey, MailgunReceiverSelector $selector): JsonResponse
    {
        return response()->json([
            'data' => MailgunReceiverResource::collection($selector->preview($mailgunKey, 100)),
            'meta' => [
                'eligible_count'   => $selector->eligible($mailgunKey),
                'next_batch_count' => $selector->batchCount($mailgunKey),
            ],
        ]);
    }

    /**
     * Queue one run for this credential now.
     *
     * Refuses for the same reasons the scheduler would, using the same method,
     * so a manual run and an automatic one can never disagree about validity.
     */
    public function runReceiverCampaign(MailgunKey $mailgunKey): JsonResponse
    {
        $reason = SendMailgunReceiverCampaignJob::blockedReason($mailgunKey);

        if ($reason !== null) {
            return response()->json(['ok' => false, 'message' => ucfirst($reason) . '.'], Response::HTTP_CONFLICT);
        }

        SendMailgunReceiverCampaignJob::dispatch($mailgunKey->id);

        return response()->json(['ok' => true, 'message' => 'Run queued.']);
    }

    /** Flip active ⇄ inactive without touching the key value. */
    public function toggle(MailgunKey $mailgunKey): MailgunKeyResource
    {
        $mailgunKey->update([
            'status' => $mailgunKey->isActive()
                ? MailgunKey::STATUS_INACTIVE
                : MailgunKey::STATUS_ACTIVE,
        ]);

        return new MailgunKeyResource($mailgunKey);
    }
}
