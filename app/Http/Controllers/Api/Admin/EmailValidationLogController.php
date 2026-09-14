<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailValidationLogResource;
use App\Models\EmailValidationLog;
use App\Support\CsvExport;
use App\Support\Validation\ValidationOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * READ-ONLY analysis surface over the validation log.
 *
 * Built for one job: deciding whether the rules are too strict. That is why the
 * six checks are filterable individually and why the aggregates exist — the
 * question "are my Risky rejections role addresses on real company domains, or
 * disposable mailboxes?" has to be answerable in the UI, because it is the
 * question that decides whether to set ALLOWED_VERDICTS=Valid,Risky.
 *
 * No store, update, destroy or re-run action. A re-run button on an audit screen
 * would spend a paid credit from the page that explains where credits went; rows
 * leave only via the scheduled `email-validation:prune`.
 */
class EmailValidationLogController extends Controller
{
    /** Sortable columns, allow-listed so the parameter cannot reach SQL. */
    private const array SORTABLE = ['created_at', 'score'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $sort = in_array($request->string('sort')->toString(), self::SORTABLE, true)
            ? $request->string('sort')->toString()
            : 'created_at';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        $logs = $this->filtered($request)
            ->with('site:id,name,slug')
            ->orderBy($sort, $direction)
            // A stable tiebreak: two rows can share a timestamp or a score, and
            // without this the paginator can repeat or skip one between pages.
            ->orderByDesc('id')
            ->paginate(min(200, max(10, $request->integer('per_page', 25))));

        return EmailValidationLogResource::collection($logs);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    /**
     * The analysis panel: everything as grouped aggregates.
     *
     * Five small GROUP BY queries rather than pulling rows into PHP — this runs
     * over the whole filtered range, which is the entire point (a histogram of
     * the current page would tell you nothing).
     */
    public function analysis(Request $request): JsonResponse
    {
        $base = fn (): Builder => $this->filtered($request);

        return response()->json([
            'total'    => $base()->count(),
            'verdicts' => $base()->select('verdict', DB::raw('COUNT(*) as total'))
                ->groupBy('verdict')->pluck('total', 'verdict'),
            'outcomes' => $base()->select('outcome', DB::raw('COUNT(*) as total'))
                ->groupBy('outcome')->pluck('total', 'outcome'),
            'reasons' => $base()->whereNotNull('reason_code')
                ->select('reason_code', DB::raw('COUNT(*) as total'))
                ->groupBy('reason_code')->orderByDesc('total')->limit(15)
                ->pluck('total', 'reason_code'),
            // Buckets of 0.1. FLOOR(score*10) keeps it one pass over the index
            // rather than ten counted subqueries.
            'score_histogram' => $base()->whereNotNull('score')
                ->select(DB::raw('FLOOR(score * 10) as bucket'), DB::raw('COUNT(*) as total'))
                ->groupBy('bucket')->orderBy('bucket')
                ->pluck('total', 'bucket'),
            'top_rejected_domains' => $base()
                ->whereIn('outcome', [ValidationOutcome::HardRejected->value, ValidationOutcome::SoftRejected->value])
                ->select(DB::raw($this->domainExpression() . ' as domain'), DB::raw('COUNT(*) as total'))
                ->groupBy('domain')->orderByDesc('total')->limit(15)
                ->pluck('total', 'domain'),
            'checks' => $this->checkCounts($request),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request)->with('site:id,name,slug')->latest('id')->cursor();

        return CsvExport::download(
            'email-validation-' . now()->format('Y-m-d') . '.csv',
            [
                'Date', 'Site', 'Email', 'Verdict', 'Score', 'Outcome', 'Reason',
                'Syntax', 'MX/A', 'Disposable', 'Role', 'Known bounces', 'Suspected bounces',
                'Suggestion', 'Cached', 'HTTP', 'Latency ms',
            ],
            (function () use ($rows) {
                $b = static fn (?bool $v): string => $v === null ? '' : ($v ? 'yes' : 'no');

                foreach ($rows as $log) {
                    yield [
                        $log->created_at?->toDateTimeString(),
                        $log->site?->name,
                        $log->email,
                        $log->verdict,
                        $log->score,
                        $log->outcome,
                        $log->reason_code,
                        $b($log->has_valid_address_syntax),
                        $b($log->has_mx_or_a_record),
                        $b($log->is_suspected_disposable_address),
                        $b($log->is_suspected_role_address),
                        $b($log->has_known_bounces),
                        $b($log->has_suspected_bounces),
                        $log->suggestion,
                        $log->was_cached ? 'yes' : 'no',
                        $log->http_status,
                        $log->latency_ms,
                    ];
                }
            })(),
        );
    }

    /**
     * The part of the address after the "@", per driver.
     *
     * SUBSTRING_INDEX is MySQL-only, and the test suite runs on SQLite — so
     * hardcoding it did not merely reduce portability, it made this whole
     * endpoint impossible to test, which is how a bug in the check counts
     * reached the browser before anyone noticed.
     */
    private function domainExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "substr(email, instr(email, '@') + 1)"
            : 'SUBSTRING_INDEX(email, "@", -1)';
    }

    /**
     * How many rows have each check TRUE, within the current filters.
     *
     * @return array<string, int>
     */
    private function checkCounts(Request $request): array
    {
        // Aliased with a prefix, NOT back onto the column's own name: the model
        // casts these six to boolean, so a SUM() aliased as `has_known_bounces`
        // comes back through the cast as `true` and every count silently
        // collapses to 1.
        $row = $this->filtered($request)
            ->selectRaw(implode(', ', array_map(
                static fn (string $c): string => "SUM(CASE WHEN {$c} = 1 THEN 1 ELSE 0 END) as agg_{$c}",
                EmailValidationLog::CHECK_COLUMNS,
            )))
            ->first();

        $counts = [];

        foreach (EmailValidationLog::CHECK_COLUMNS as $column) {
            $counts[$column] = (int) ($row?->getAttributes()['agg_' . $column] ?? 0);
        }

        return $counts;
    }

    /**
     * The one filter builder, shared by the listing, count, analysis and export.
     *
     * Shared on purpose: four copies would drift, and a histogram that describes
     * a different set than the table below it is worse than no histogram.
     *
     * @return Builder<EmailValidationLog>
     */
    private function filtered(Request $request): Builder
    {
        $query = EmailValidationLog::query()
            ->when($request->filled('site_id'), fn (Builder $q) => $q->where('site_id', $request->integer('site_id')))
            ->when($request->filled('verdict'), fn (Builder $q) => $q->where('verdict', $request->string('verdict')))
            ->when($request->filled('outcome'), fn (Builder $q) => $q->where('outcome', $request->string('outcome')))
            ->when($request->filled('reason_code'), fn (Builder $q) => $q->where('reason_code', $request->string('reason_code')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('min_score'), fn (Builder $q) => $q->where('score', '>=', (float) $request->input('min_score')))
            ->when($request->filled('max_score'), fn (Builder $q) => $q->where('score', '<=', (float) $request->input('max_score')))
            ->when($request->filled('cached'), fn (Builder $q) => $q->where('was_cached', $request->boolean('cached')));

        // Each of the six checks, tri-state: true / false / any (absent).
        // This is what makes "Risky AND not disposable AND role address"
        // expressible — the query that decides whether to accept Risky.
        foreach (EmailValidationLog::CHECK_COLUMNS as $column) {
            $query->when(
                $request->filled($column),
                fn (Builder $q) => $q->where($column, $request->boolean($column)),
            );
        }

        return $query->when($request->filled('search'), function (Builder $q) use ($request): void {
            $term = (string) $request->string('search');
            // LIKE metacharacters in operator input would otherwise turn a
            // search for "a_b" into a wildcard.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

            // ESCAPE is stated EXPLICITLY: MySQL happens to default to
            // backslash, SQLite has no default at all, so the escaping above is
            // silently ignored there and "a_b" matches "axb".
            $like = static fn (string $pattern) => static fn ($q) => $q->whereRaw(
                'email LIKE ? ESCAPE ?',
                [$pattern, '\\'],
            );

            match ($request->string('search_mode')->toString()) {
                // Domain mode: "@gmail.com" — anchored on the domain so one
                // provider dominating the rejections is visible at a glance.
                'domain' => $like('%@' . ltrim($escaped, '@'))($q),
                'exact'  => $q->where('email', '=', $term),
                default  => $like('%' . $escaped . '%')($q),
            };
        });
    }
}
