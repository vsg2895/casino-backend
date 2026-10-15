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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Send runs and the send log. */
class UniOneSendController extends Controller
{
    public function __construct(
        private readonly UniOneSendService $sends,
        private readonly UniOneKeyService $keys,
    ) {}

    /**
     * The template a run sends, rendered for the operator to look at first.
     *
     * There is exactly one — viglinksi's promotion template — so this returns
     * the rendered HTML and its subject rather than a list to choose from.
     */
    public function templatePreview(\App\Services\UniOne\UniOneTemplateService $templates): JsonResponse
    {
        return response()->json(['data' => $templates->preview()]);
    }

    /** The modal's live preview: eligible count and chunk maths. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count'         => ['required', 'integer', 'min:1', 'max:100000'],
            'cooldown_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        return response()->json([
            'data' => $this->sends->preview((int) $data['count'], $data['cooldown_days'] ?? null),
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
            'subject'    => ['nullable', 'string', 'max:255'],
            'from_email' => ['required', 'email'],
            'from_name'  => ['nullable', 'string', 'max:120'],
            'reply_to'   => ['nullable', 'email'],
        ]);

        $key = $this->keys->find((int) $data['unione_api_key_id']);

        if ($key === null) {
            return $this->noKey();
        }

        /*
         * The test goes through the SAME path a run does — same shared body,
         * same substitutions, same template engine — not just the same
         * template. Rendering it a second way would prove the renderer works
         * while leaving everything the provider does to the message untested,
         * which is exactly where the missing hero image lived.
         */
        $templates = app(\App\Services\UniOne\UniOneTemplateService::class);
        $shared = $templates->sharedBody();

        $response = (new UniOneClient($key))->send(array_filter([
            'recipients' => [[
                'email'         => $data['email'],
                'substitutions' => $templates->substitutionsFor($data['email'], null),
            ]],
            'subject'    => ($data['subject'] ?? null) ?: $templates->subjectFor(),
            'from_email' => $data['from_email'],
            'from_name'  => $data['from_name'] ?? null,
            'reply_to'   => $data['reply_to'] ?? null,
            'body'       => ['html' => $shared['html'], 'plaintext' => $shared['plaintext']],
            'template_engine' => 'simple',
            'track_links' => $key->track_links ? 1 : 0,
            'track_read'  => $key->track_read ? 1 : 0,
            'skip_unsubscribe' => 0,
        ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []));

        /*
         * Recorded as a one-recipient run, flagged `is_test`.
         *
         * The test goes through the same client, the same body and the same
         * substitutions a real run uses, so it belongs in the same log — and a
         * test that left no trace was exactly the gap an operator hit when
         * asking "did my test actually reach UniOne, and what did it say?".
         *
         * Flagged because it is not a campaign: one row with requested=1 next
         * to runs of thousands would otherwise read as a failed run.
         */
        $this->recordTest($key, $data, $response);

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
            'count'         => ['required', 'integer', 'min:1', 'max:100000'],
            'cooldown_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // The template supplies the subject when this is left blank.
            'subject'    => ['nullable', 'string', 'max:255'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name'  => ['nullable', 'string', 'max:120'],
            'reply_to'   => ['nullable', 'email', 'max:255'],
        ]);

        $key = $this->keys->find((int) $data['unione_api_key_id']);

        if ($key === null) {
            return $this->noKey();
        }

        $data['html_body'] ??= '';
        $data['subject'] ??= '';

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
            // Without the two LONGTEXT message bodies, which no response
            // returns — see UniOneSend::LIST_COLUMNS.
            ->paginate($filters['per_page'] ?? 25, UniOneSend::LIST_COLUMNS);

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
    /**
     * Delete one run from the history.
     *
     * Its chunks and per-recipient rows go with it — both are
     * `ON DELETE CASCADE`, which is what keeps a deleted run from leaving
     * detail rows pointing at nothing.
     */
    public function destroy(UniOneSend $uniOneSend): JsonResponse
    {
        $uniOneSend->delete();

        return response()->json(null, 204);
    }

    /**
     * Log one "Send test" as a run of one.
     *
     * Best-effort: the message has already left, so a history write that fails
     * is logged and never changes what the operator is told about the send.
     *
     * @param  array<string, mixed>  $data  the validated test payload
     */
    private function recordTest(UniOneApiKey $key, array $data, mixed $response): void
    {
        try {
            UniOneSend::create([
                'unione_api_key_id' => $key->id,
                'user_id'           => auth()->id(),
                'subject'           => (string) (($data['subject'] ?? null) ?: app(\App\Services\UniOne\UniOneTemplateService::class)->subjectFor()),
                'from_email'        => (string) $data['from_email'],
                'from_name'         => $data['from_name'] ?? null,
                'reply_to'          => $data['reply_to'] ?? null,
                // The bodies are NOT copied: a test re-renders the shared
                // template every time, so storing a snapshot of it here would
                // be two LONGTEXT columns of something nothing reads back.
                'html_body'         => '',
                'requested_count'   => 1,
                'eligible_count'    => 1,
                'accepted_count'    => $response->ok ? 1 : 0,
                'failed_count'      => $response->ok ? 0 : 1,
                'chunk_count'       => 0,
                'status'            => $response->ok ? UniOneSend::STATUS_COMPLETED : UniOneSend::STATUS_FAILED,
                'error'            => $response->ok ? null : mb_strimwidth((string) $this->explain($response), 0, 500, '…'),
                'is_test'           => true,
                'completed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('UniOne test send history write failed', [
                'key_id' => $key->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

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
