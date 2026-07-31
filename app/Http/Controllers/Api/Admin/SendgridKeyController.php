<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendSendgridKeyTestRequest;
use App\Http\Requests\Admin\StoreSendgridKeyRequest;
use App\Http\Requests\Admin\UpdateSendgridKeyRequest;
use App\Http\Resources\SendgridKeyResource;
use App\Models\Newsletter;
use App\Models\SendgridKey;
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
 * Admin CRUD for stored SendGrid API keys used by scheduled promotion sends.
 *
 * The raw key is write-only: accepted on create/update, never returned (the
 * Resource exposes only a masked preview). Deleting a key nulls it out on any
 * schedule that referenced it (FK nullOnDelete) — those schedules then fail
 * gracefully at send time until a new key is chosen.
 */
class SendgridKeyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SendgridKey::query()->latest();

        // Optional ?status=active filter (used by the schedule dropdown).
        if (in_array($request->query('status'), SendgridKey::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return SendgridKeyResource::collection($query->get());
    }

    public function store(StoreSendgridKeyRequest $request): JsonResponse
    {
        $key = SendgridKey::create([
            'status' => SendgridKey::STATUS_ACTIVE,
            ...$request->validated(),
        ]);

        return (new SendgridKeyResource($key))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateSendgridKeyRequest $request, SendgridKey $sendgridKey): SendgridKeyResource
    {
        // api_key is only present when the admin actually typed a new one, so a
        // blank edit preserves the stored key (see the Form Request).
        $sendgridKey->update($request->validated());

        return new SendgridKeyResource($sendgridKey);
    }

    public function destroy(SendgridKey $sendgridKey): JsonResponse
    {
        $sendgridKey->delete();

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
     * it builds the per-key SendGrid transport instead.
     *
     * Runs synchronously and surfaces the transport's real message on failure
     * (e.g. SendGrid's 401 for a revoked key), so the admin gets a definitive
     * works / does-not-work answer. Inactive keys can be tested too: verifying
     * a key before enabling it is a legitimate workflow.
     */
    public function test(
        SendSendgridKeyTestRequest $request,
        SendgridKey $sendgridKey,
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
            // If SendGrid rejects that sender as unverified, the 502 below
            // reports it — which is itself a useful result of the test.
            $mailable = $catalog->build($type, $site, $newsletter);
            $mailers->mailerForKey($sendgridKey)->to($to)->send($mailable);
        } catch (Throwable $e) {
            Log::warning('SendGrid key test failed', [
                'sendgrid_key_id' => $sendgridKey->id,
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
                $sendgridKey->name,
            ),
        ]);
    }

    /** Flip active ⇄ inactive without touching the key value. */
    public function toggle(SendgridKey $sendgridKey): SendgridKeyResource
    {
        $sendgridKey->update([
            'status' => $sendgridKey->isActive()
                ? SendgridKey::STATUS_INACTIVE
                : SendgridKey::STATUS_ACTIVE,
        ]);

        return new SendgridKeyResource($sendgridKey);
    }
}
