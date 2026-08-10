<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSmsTemplateRequest;
use App\Http\Requests\Admin\UpdateSmsTemplateRequest;
use App\Http\Resources\SmsTemplateResource;
use App\Models\SmsTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Admin CRUD for reusable SMS message texts.
 *
 * Same contract as the credential screens — index / store / update / destroy /
 * toggle — so the admin panel behaves consistently.
 *
 * Not paginated, on purpose: templates are a small hand-curated set (a handful,
 * not thousands), and the send dialog's dropdown wants them all in one call. If
 * that ever stops being true, this needs the same lazy listing + dedicated COUNT
 * the phone list uses.
 */
class SmsTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SmsTemplate::query()->orderBy('name');

        // Optional ?status=active filter — this is what the send dialog asks for,
        // so a deactivated template disappears from the picker without being
        // deleted.
        if (in_array($request->query('status'), SmsTemplate::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return SmsTemplateResource::collection($query->get());
    }

    public function store(StoreSmsTemplateRequest $request): JsonResponse
    {
        $template = SmsTemplate::create([
            'status' => SmsTemplate::STATUS_ACTIVE,
            ...$request->validated(),
        ]);

        return (new SmsTemplateResource($template))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update a template.
     *
     * This is the whole point of the feature: change the wording here and the
     * next send starts from the new text. It deliberately does NOT touch runs
     * already queued or already sent — the body travels with the job and is
     * recorded per recipient, so history stays a truthful record of what went out.
     */
    public function update(UpdateSmsTemplateRequest $request, SmsTemplate $smsTemplate): SmsTemplateResource
    {
        $smsTemplate->update($request->validated());

        return new SmsTemplateResource($smsTemplate);
    }

    public function destroy(SmsTemplate $smsTemplate): JsonResponse
    {
        $smsTemplate->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Flip active ⇄ inactive.
     *
     * Retiring a template without deleting it: it drops out of the send dialog
     * but stays available to reactivate, which beats keeping a "do not use"
     * entry in the picker.
     */
    public function toggle(SmsTemplate $smsTemplate): SmsTemplateResource
    {
        $smsTemplate->update([
            'status' => $smsTemplate->isActive()
                ? SmsTemplate::STATUS_INACTIVE
                : SmsTemplate::STATUS_ACTIVE,
        ]);

        return new SmsTemplateResource($smsTemplate);
    }
}
