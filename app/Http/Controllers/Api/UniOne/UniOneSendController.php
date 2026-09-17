<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\UniOne;

use App\Http\Controllers\Controller;
use App\Http\Resources\UniOne\UniOneSendResource;
use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneSend;
use App\Models\UniOne\UniOneSendRecipient;
use App\Services\UniOne\UniOneClient;
use App\Services\UniOne\UniOneKeyService;
use App\Services\UniOne\UniOneSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Send runs and the send log. */
class UniOneSendController extends Controller
{
    public function __construct(
        private readonly UniOneSendService $sends,
        private readonly UniOneKeyService $keys,
    ) {}

    /** The modal's live preview: eligible count and chunk maths. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count'          => ['required', 'integer', 'min:1', 'max:100000'],
            'cooldown_hours' => ['nullable', 'integer', 'min:0', 'max:8760'],
        ]);

        return response()->json([
            'data' => $this->sends->preview((int) $data['count'], $data['cooldown_hours'] ?? null),
        ]);
    }

    /**
     * Send to one address, without touching the list.
     *
     * Deliberately synchronous and deliberately outside the send pipeline: it
     * writes no send row, stamps no `last_sent_at` and consumes no receiver. An
     * operator is watching and wants the answer now.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unione_api_key_id' => ['required', 'integer'],
            'email'      => ['required', 'email'],
            'subject'    => ['required', 'string', 'max:255'],
            'from_email' => ['required', 'email'],
            'from_name'  => ['nullable', 'string', 'max:120'],
            'reply_to'   => ['nullable', 'email'],
            'html_body'  => ['required', 'string'],
            'plaintext_body' => ['nullable', 'string'],
        ]);

        $key = $this->keys->find((int) $data['unione_api_key_id']);

        if ($key === null) {
            return $this->noKey();
        }

        $response = (new UniOneClient($key))->send(array_filter([
            'recipients' => [['email' => $data['email']]],
            'subject'    => $data['subject'],
            'from_email' => $data['from_email'],
            'from_name'  => $data['from_name'] ?? null,
            'reply_to'   => $data['reply_to'] ?? null,
            'body'       => array_filter([
                'html'      => $data['html_body'],
                'plaintext' => $data['plaintext_body'] ?? null,
            ]),
            'track_links' => $key->track_links ? 1 : 0,
            'track_read'  => $key->track_read ? 1 : 0,
            'skip_unsubscribe' => 0,
        ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []));

        return response()->json([
            'ok'      => $response->ok,
            'job_id'  => $response->jobId,
            'failed'  => $response->failedEmails,
            'message' => $this->explain($response),
            'code'    => $response->apiErrorCode,
        ], $response->ok ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unione_api_key_id' => ['required', 'integer'],
            'count'      => ['required', 'integer', 'min:1', 'max:100000'],
            'cooldown_hours' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'subject'    => ['required', 'string', 'max:255'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name'  => ['nullable', 'string', 'max:120'],
            'reply_to'   => ['nullable', 'email', 'max:255'],
            'html_body'  => ['required', 'string'],
            'plaintext_body' => ['nullable', 'string'],
        ]);

        $key = $this->keys->find((int) $data['unione_api_key_id']);

        if ($key === null) {
            return $this->noKey();
        }

        $send = $this->sends->dispatchRun($key, $data, $request->user()?->id);

        return response()->json([
            'data' => (new UniOneSendResource($send->load('key')))->resolve(),
        ], Response::HTTP_ACCEPTED);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'   => ['nullable', 'string', 'max:12'],
            'key_id'   => ['nullable', 'integer'],
            'search'   => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $sends = UniOneSend::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['key_id'] ?? null, fn ($q, $v) => $q->where('unione_api_key_id', $v))
            ->search($filters['search'] ?? null)
            ->with('key:id,name,key_type,region')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => UniOneSendResource::collection($sends->items())->resolve(),
            'meta' => [
                'current_page' => $sends->currentPage(),
                'last_page'    => $sends->lastPage(),
                'total'        => $sends->total(),
            ],
        ]);
    }

    /** Row detail: the chunks, plus every failed address and its reason. */
    public function show(UniOneSend $uniOneSend): JsonResponse
    {
        $failed = UniOneSendRecipient::query()
            ->where('unione_send_id', $uniOneSend->id)
            ->where('status', UniOneSendRecipient::STATUS_FAILED)
            ->orderBy('id')
            ->limit(2000)
            ->get(['email', 'reason']);

        return response()->json([
            'data' => [
                ...(new UniOneSendResource($uniOneSend->load(['key', 'chunks'])))->resolve(),
                'failed_emails' => $failed->map(static fn ($r): array => [
                    'email' => $r->email, 'reason' => $r->reason,
                ])->all(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = UniOneSend::query()->with('key:id,name')->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['id', 'created_at', 'key', 'subject', 'from_email', 'status',
                'requested', 'eligible', 'accepted', 'failed', 'chunks', 'completed_at']);

            $query->chunkById(500, function ($rows) use ($out): void {
                foreach ($rows as $s) {
                    fputcsv($out, [
                        $s->id, $s->created_at?->toDateTimeString(), $s->key?->name, $s->subject,
                        $s->from_email, $s->status, $s->requested_count, $s->eligible_count,
                        $s->accepted_count, $s->failed_count, $s->chunk_count,
                        $s->completed_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, 'unione-send-log-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The "no usable key" answer.
     *
     * The brief: everything degrades safely when no active key exists — a clear
     * message, nothing attempted, nothing crashed.
     */
    private function noKey(): JsonResponse
    {
        return response()->json([
            'message' => 'No active UniOne key is available. Add one in the UniOne section and verify it before sending.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Turn a UniOne rejection into something an operator can act on.
     *
     * The tracking case is called out because the brief asks for it: setting
     * track_links/track_read to 0 needs UniOne's support to enable the option,
     * and the raw API message does not say so.
     */
    private function explain(\App\Support\UniOne\UniOneResponse $response): ?string
    {
        return $response->ok
            ? null
            : \App\Support\UniOne\UniOneErrors::explain(
                $response->apiErrorCode,
                $response->message,
                $response->httpStatus,
            );
    }
}
