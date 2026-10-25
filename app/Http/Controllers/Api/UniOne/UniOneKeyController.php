<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\UniOne;

use App\Http\Controllers\Controller;
use App\Http\Requests\UniOne\RecheckUniOneDomainRequest;
use App\Http\Requests\UniOne\StoreUniOneKeyRequest;
use App\Http\Requests\UniOne\UniOneDomainDnsRequest;
use App\Http\Requests\UniOne\UpdateUniOneKeyRequest;
use App\Http\Resources\UniOne\UniOneApiKeyResource;
use App\Models\UniOne\UniOneApiKey;
use App\Services\UniOne\UniOneClient;
use App\Services\UniOne\UniOneKeyService;
use App\Services\UniOne\UniOneWebhookRegistrar;
use App\Support\UniOne\UniOneResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD for UniOne API keys, plus the Domains and Suppressions helper tabs.
 *
 * Mirrors the SendgridKeyController contract — index/store/update/destroy plus
 * per-key actions — as new, independent code. Nothing in the SendGrid section is
 * read, imported or changed.
 */
class UniOneKeyController extends Controller
{
    public function __construct(private readonly UniOneKeyService $keys) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'key_type' => ['nullable', Rule::in(UniOneApiKey::TYPES)],
            'region' => ['nullable', Rule::in(UniOneApiKey::REGIONS)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = UniOneApiKey::query()
            ->when($filters['key_type'] ?? null, fn ($q, $v) => $q->where('key_type', $v))
            ->when($filters['region'] ?? null, fn ($q, $v) => $q->where('region', $v))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('is_default')
            ->orderBy('name');

        return UniOneApiKeyResource::collection($query->get());
    }

    public function store(StoreUniOneKeyRequest $request): JsonResponse
    {
        $key = UniOneApiKey::query()->create($request->validated());

        $this->keys->forget($key->id);

        return (new UniOneApiKeyResource($key))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateUniOneKeyRequest $request, UniOneApiKey $uniOneKey): UniOneApiKeyResource
    {
        $data = $request->validated();

        // Replace, never reveal: an empty api_key means "leave it alone", which
        // is what lets the edit form open without ever holding the secret.
        $replacesSecret = ($data['api_key'] ?? '') !== '';

        if (! $replacesSecret) {
            unset($data['api_key']);
        }

        $uniOneKey->fill($data);

        if ($replacesSecret) {
            // A replaced key invalidates its own verification — the new
            // credential has not been proven, and an unverified key cannot be
            // made default (see UniOneKeyService::makeDefault).
            //
            // forceFill, not fill(): neither column is fillable, on purpose, so
            // that only verify() can mark a key proven. Passed through fill()
            // this reset was silently discarded and a rotated key stayed
            // "verified". `is_default` is deliberately untouched: a rotated
            // default keeps sending, it just shows as unverified until checked.
            $uniOneKey->forceFill(['last_verified_at' => null, 'last_verify_status' => null]);
        }

        $uniOneKey->save();

        // Busted on EVERY write, or a rotated key keeps sending with the old
        // credential until the TTL expires.
        $this->keys->forget($uniOneKey->id);

        return new UniOneApiKeyResource($uniOneKey->refresh());
    }

    public function destroy(UniOneApiKey $uniOneKey): JsonResponse
    {
        if ($uniOneKey->is_default) {
            return response()->json([
                'message' => 'That is the default key. Make another key the default before deleting this one.',
            ], Response::HTTP_CONFLICT);
        }

        $uniOneKey->delete();
        $this->keys->forget($uniOneKey->id);

        return response()->json(['deleted' => true]);
    }

    public function toggle(UniOneApiKey $uniOneKey): JsonResponse
    {
        if ($uniOneKey->is_default && $uniOneKey->is_active) {
            return response()->json([
                'message' => 'Deactivating the default key would leave no key to send with. Promote another key first.',
            ], Response::HTTP_CONFLICT);
        }

        $uniOneKey->forceFill(['is_active' => ! $uniOneKey->is_active])->save();
        $this->keys->forget($uniOneKey->id);

        return response()->json(['data' => (new UniOneApiKeyResource($uniOneKey))->resolve()]);
    }

    /** Verify against /system/ping.json and /system/info.json. */
    public function verify(UniOneApiKey $uniOneKey): JsonResponse
    {
        $result = $this->keys->verify($uniOneKey);

        return response()->json([
            'data' => [
                'ok' => $result['ok'],
                'status' => $result['status'],
                // Account details from /system/info.json — never the key.
                'info' => $result['info'],
                'key' => (new UniOneApiKeyResource($uniOneKey->refresh()))->resolve(),
            ],
        ], $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Tell UniOne where to post this key's delivery events.
     *
     * The step that was missing. Everything on the receiving side existed — the
     * endpoint, its token gate, the MD5 check, the de-duplication and the status
     * mapping — but nothing ever called `webhook/set.json`, so UniOne had no URL
     * to post to. Failures were counted in their dashboard and the receiver list
     * never heard about them.
     *
     * An explicit action rather than something a key save does silently: it
     * reaches out to a third party, and an operator pressing a button knows to
     * read the answer that comes back.
     *
     * Sending is untouched — a different endpoint, a different payload, and
     * nothing in the send path reads what this stores.
     */
    public function registerWebhook(UniOneApiKey $uniOneKey, UniOneWebhookRegistrar $registrar): JsonResponse
    {
        // The same URL the admin displays, built in one place so the address
        // registered can never differ from the one an operator is looking at.
        $url = url("/api/v1/unione/webhook/{$uniOneKey->webhook_secret}");

        $result = $registrar->register($uniOneKey, $url);

        return response()->json([
            'data' => [
                'ok' => $result['ok'],
                'message' => $result['message'],
                'key' => (new UniOneApiKeyResource($uniOneKey->refresh()))->resolve(),
            ],
        ], $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function makeDefault(UniOneApiKey $uniOneKey): JsonResponse
    {
        // Throws ValidationException when the key is inactive or unverified —
        // both rules live in the service, not here.
        $key = $this->keys->makeDefault($uniOneKey);

        return response()->json(['data' => (new UniOneApiKeyResource($key))->resolve()]);
    }

    // ── helper tabs ──────────────────────────────────────────────────────────

    public function domains(UniOneApiKey $uniOneKey): JsonResponse
    {
        return $this->passthrough((new UniOneClient($uniOneKey))->domains());
    }

    public function domainDns(UniOneDomainDnsRequest $request, UniOneApiKey $uniOneKey): JsonResponse
    {
        $data = $request->validated();

        return $this->passthrough((new UniOneClient($uniOneKey))->domainDnsRecords($data['domain']));
    }

    public function recheckDomain(RecheckUniOneDomainRequest $request, UniOneApiKey $uniOneKey): JsonResponse
    {
        $data = $request->validated();

        $client = new UniOneClient($uniOneKey);

        $response = $data['check'] === 'dkim'
            ? $client->validateDkim($data['domain'])
            : $client->validateVerificationRecord($data['domain']);

        return $this->passthrough($response);
    }

    public function suppressions(Request $request, UniOneApiKey $uniOneKey): JsonResponse
    {
        $filters = $request->validate([
            'cause' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:40'],
            'cursor' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->passthrough((new UniOneClient($uniOneKey))->suppressions($filters));
    }

    /**
     * Forward a UniOne response to the admin.
     *
     * The raw body is safe to return: none of these endpoints echo the API key,
     * and an operator authenticating a domain needs UniOne's exact wording and
     * DNS values rather than a paraphrase.
     */
    private function passthrough(UniOneResponse $response): JsonResponse
    {
        return response()->json([
            'ok' => $response->ok,
            'data' => $response->raw,
            'message' => $response->message,
            'code' => $response->apiErrorCode,
        ], $response->ok ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
