<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendMailgunKeyTestRequest;
use App\Http\Requests\Admin\StoreMailgunKeyRequest;
use App\Http\Requests\Admin\UpdateMailgunKeyRequest;
use App\Http\Resources\MailgunKeyResource;
use App\Models\Newsletter;
use App\Models\MailgunKey;
use App\Models\Site;
use App\Services\Mail\EmailTemplateCatalog;
use App\Services\Mail\PromotionMailerFactory;
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
 * Resource exposes only a masked preview). Deleting a key nulls it out on any
 * schedule that referenced it (FK nullOnDelete) — those schedules then fail
 * gracefully at send time until a new key is chosen.
 */
class MailgunKeyController extends Controller
{
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

    public function destroy(MailgunKey $mailgunKey): JsonResponse
    {
        $mailgunKey->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Send a REAL site email template THROUGH this stored key to prove it works.
     *
     * The admin picks the template (see {@see EmailTemplateCatalog}) and the
     * website it should be rendered for, so the test exercises the exact content
     * a live send would produce — not placeholder text.
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
        $site = Site::findOrFail($request->integer('site_id'));

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
