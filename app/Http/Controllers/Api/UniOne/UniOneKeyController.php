<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\UniOne;

use App\Http\Controllers\Controller;
use App\Http\Resources\UniOne\UniOneApiKeyResource;
use App\Models\UniOne\UniOneApiKey;
use App\Services\UniOne\UniOneClient;
use App\Services\UniOne\UniOneKeyService;
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
            'key_type'  => ['nullable', Rule::in(UniOneApiKey::TYPES)],
            'region'    => ['nullable', Rule::in(UniOneApiKey::REGIONS)],
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

    public function store(Request $request): JsonResponse
    {
        $key = UniOneApiKey::query()->create($this->validated($request, null));

        $this->keys->forget($key->id);

        return (new UniOneApiKeyResource($key))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, UniOneApiKey $uniOneKey): UniOneApiKeyResource
    {
        $data = $this->validated($request, $uniOneKey);

        // Replace, never reveal: an empty api_key means "leave it alone", which
        // is what lets the edit form open without ever holding the secret.
        if (($data['api_key'] ?? '') === '') {
            unset($data['api_key']);
        } else {
            // A replaced key invalidates its own verification — the new
            // credential has not been proven, and an unverified key cannot be
            // default (see UniOneKeyService::makeDefault).
            $data['last_verified_at'] = null;
            $data['last_verify_status'] = null;
        }

        $uniOneKey->fill($data)->save();

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
                'ok'     => $result['ok'],
                'status' => $result['status'],
                // Account details from /system/info.json — never the key.
                'info'   => $result['info'],
                'key'    => (new UniOneApiKeyResource($uniOneKey->refresh()))->resolve(),
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

    public function domainDns(Request $request, UniOneApiKey $uniOneKey): JsonResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:255']]);

        return $this->passthrough((new UniOneClient($uniOneKey))->domainDnsRecords($data['domain']));
    }

    public function recheckDomain(Request $request, UniOneApiKey $uniOneKey): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'check'  => ['required', Rule::in(['dkim', 'verification'])],
        ]);

        $client = new UniOneClient($uniOneKey);

        $response = $data['check'] === 'dkim'
            ? $client->validateDkim($data['domain'])
            : $client->validateVerificationRecord($data['domain']);

        return $this->passthrough($response);
    }

    public function suppressions(Request $request, UniOneApiKey $uniOneKey): JsonResponse
    {
        $filters = $request->validate([
            'cause'  => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:40'],
            'cursor' => ['nullable', 'string', 'max:255'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->passthrough((new UniOneClient($uniOneKey))->suppressions($filters));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?UniOneApiKey $existing): array
    {
        return $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            // Required on create, optional on update — see update().
            'api_key'  => [$existing === null ? 'required' : 'nullable', 'string', 'max:500'],
            'key_type' => ['required', Rule::in(UniOneApiKey::TYPES)],
            'project_id' => ['nullable', 'required_if:key_type,project', 'string', 'max:64'],
            'region'   => ['required', Rule::in(UniOneApiKey::REGIONS)],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'is_active' => ['sometimes', 'boolean'],
            'default_from_email' => ['nullable', 'email', 'max:255'],
            'default_from_name'  => ['nullable', 'string', 'max:120'],
            'track_links' => ['sometimes', 'boolean'],
            'track_read'  => ['sometimes', 'boolean'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * Forward a UniOne response to the admin.
     *
     * The raw body is safe to return: none of these endpoints echo the API key,
     * and an operator authenticating a domain needs UniOne's exact wording and
     * DNS values rather than a paraphrase.
     */
    private function passthrough(\App\Support\UniOne\UniOneResponse $response): JsonResponse
    {
        return response()->json([
            'ok'      => $response->ok,
            'data'    => $response->raw,
            'message' => $response->message,
            'code'    => $response->apiErrorCode,
        ], $response->ok ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
