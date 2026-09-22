<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Jobs\UniOne\SendUniOneChunkJob;
use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneSend;
use App\Models\UniOne\UniOneSendChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds a send run and puts its chunks on the queue.
 *
 * Never sends inline. This method returns as soon as the rows are written and
 * the jobs are dispatched — the HTTP request that triggered it does not wait on
 * UniOne, which is the brief's requirement and also what stops a 30-second API
 * stall from timing out an admin's browser.
 */
class UniOneSendService
{
    /**
     * Marks a send whose body is rendered per recipient from a template.
     *
     * A prefix on `html_body` rather than a new column: the send log still has
     * one place to look for "what was sent", and an old run's literal HTML is
     * still readable as itself.
     */
    public const string TEMPLATE_MARKER = 'unione-template:';

    public function __construct(
        private readonly UniOneAudienceService $audience,
        private readonly UniOneTemplateService $templates,
    ) {}

    /**
     * @param  array{subject: string, from_email: string, from_name?: ?string, reply_to?: ?string, html_body: string, plaintext_body?: ?string, count: int, cooldown_hours?: ?int}  $input
     *
     * @throws ValidationException
     */
    public function dispatchRun(UniOneApiKey $key, array $input, ?int $userId): UniOneSend
    {
        if (! $key->is_active) {
            throw ValidationException::withMessages(['unione_api_key_id' => 'That key is not active.']);
        }

        $cooldown = $input['cooldown_hours'] ?? null;

        $receivers = $this->audience->select((int) $input['count'], $cooldown);

        if ($receivers->isEmpty()) {
            throw ValidationException::withMessages([
                'count' => 'No receivers are eligible right now — every active address is inside the cooldown, or the list is empty.',
            ]);
        }

        $chunks = $receivers->chunk(UniOneSend::CHUNK_SIZE)->values();

        /*
         * The rows and the dispatches are separated deliberately.
         *
         * Everything persistent is written inside the transaction; the jobs are
         * dispatched AFTER it commits. Dispatching inside would let a worker
         * pick up a chunk whose row is not yet visible to its connection, and
         * the job would find nothing and return silently.
         */
        /*
         * TEMPLATE mode, mirroring how Warmup sends.
         *
         * Warmup picks a template and the server renders it; UniOne does the
         * same with crogambline's promotion template. The stored `html_body` is
         * then a MARKER rather than markup — the real HTML is rendered per
         * recipient at send time, because the template personalises the
         * greeting and one shared body would address everybody identically.
         *
         * Raw HTML is still accepted, so a one-off blast does not need a
         * template row.
         */
        $usesTemplate = ($input['template'] ?? null) !== null && ($input['template'] ?? '') !== '';

        if ($usesTemplate) {
            $input['html_body'] = self::TEMPLATE_MARKER . $input['template'];

            if (trim((string) ($input['subject'] ?? '')) === '') {
                $input['subject'] = $this->templates->subjectFor();
            }
        }

        $send = DB::transaction(function () use ($key, $input, $userId, $receivers, $chunks): UniOneSend {
            $send = UniOneSend::query()->create([
                'unione_api_key_id' => $key->id,
                'user_id'           => $userId,
                'subject'           => $input['subject'],
                'from_email'        => $input['from_email'],
                'from_name'         => $input['from_name'] ?? $key->default_from_name,
                'reply_to'          => $input['reply_to'] ?? null,
                'html_body'         => $input['html_body'],
                'plaintext_body'    => $input['plaintext_body'] ?? null,
                'requested_count'   => (int) $input['count'],
                'cooldown_hours'    => (int) ($input['cooldown_hours'] ?? 0),
                'eligible_count'    => $receivers->count(),
                'chunk_count'       => $chunks->count(),
                'status'            => UniOneSend::STATUS_SENDING,
            ]);

            foreach ($chunks as $index => $slice) {
                UniOneSendChunk::query()->create([
                    'unione_send_id'  => $send->id,
                    'chunk_index'     => $index,
                    // Deterministic, so a retry reproduces the same key.
                    'idempotence_key' => UniOneSendChunk::idempotenceKeyFor($send->id, $index),
                    'recipient_count' => $slice->count(),
                    'status'          => UniOneSendChunk::STATUS_PENDING,
                ]);
            }

            return $send;
        });

        foreach ($chunks as $index => $slice) {
            SendUniOneChunkJob::dispatch($send->id, $index, $slice->pluck('id')->all());
        }

        return $send->refresh();
    }

    /**
     * What the confirmation step shows before anything is dispatched.
     *
     * @return array{eligible: int, will_send: int, chunks: int, chunk_size: int}
     */
    public function preview(int $requested, ?int $cooldownHours): array
    {
        $eligible = $this->audience->eligibleCount($cooldownHours);
        $willSend = min($eligible, max(0, $requested));

        return [
            'eligible'   => $eligible,
            'will_send'  => $willSend,
            'chunks'     => $this->audience->chunkCount($willSend),
            'chunk_size' => UniOneSend::CHUNK_SIZE,
        ];
    }
}
