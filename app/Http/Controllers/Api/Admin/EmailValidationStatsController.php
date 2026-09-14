<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailValidationLog;
use App\Models\Site;
use App\Services\Validation\SendGridEmailValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where the month's validation credits went.
 *
 * One row per registered site — including sites with zero attempts, because an
 * absent row reads as "no data" when the true answer is "nobody subscribed",
 * and those are different problems.
 *
 * Every aggregate comes from ONE grouped query rather than a query per site or
 * per verdict: this screen shows six sites × nine counters, and the naive shape
 * would be fifty round trips to render a summary.
 */
class EmailValidationStatsController extends Controller
{
    public function __construct(private readonly SendGridEmailValidationService $sendgrid) {}

    public function index(Request $request): JsonResponse
    {
        // 'all' is an explicit choice, not an empty filter — the month selector
        // defaults to the current month and the operator opts into all-time.
        $month = (string) $request->string('month', EmailValidationLog::currentQuotaMonth());
        $allTime = $month === 'all';

        $rows = EmailValidationLog::query()
            ->when(! $allTime, fn ($q) => $q->where('quota_month', $month))
            ->selectRaw('site_id')
            ->selectRaw('COUNT(*) as attempts')
            // "billed" is defined once, on the model; these mirror it rather
            // than restating the rule.
            ->selectRaw("SUM(CASE WHEN was_cached = 0 AND (reason_code IS NULL OR reason_code NOT IN ('missing_key','quota_exhausted','email_cooldown','disabled','pending_resend')) THEN 1 ELSE 0 END) as api_calls")
            ->selectRaw('SUM(was_cached) as cache_hits')
            ->selectRaw("SUM(CASE WHEN reason_code IN ('missing_key','quota_exhausted','email_cooldown','disabled','pending_resend') THEN 1 ELSE 0 END) as skipped")
            ->selectRaw("SUM(CASE WHEN verdict = 'Valid' THEN 1 ELSE 0 END) as valid")
            ->selectRaw("SUM(CASE WHEN verdict = 'Risky' THEN 1 ELSE 0 END) as risky")
            ->selectRaw("SUM(CASE WHEN verdict = 'Invalid' THEN 1 ELSE 0 END) as invalid")
            ->selectRaw("SUM(CASE WHEN outcome = 'allowed' THEN 1 ELSE 0 END) as allowed")
            ->selectRaw("SUM(CASE WHEN outcome = 'hard_rejected' THEN 1 ELSE 0 END) as hard_rejected")
            ->selectRaw("SUM(CASE WHEN outcome = 'soft_rejected' THEN 1 ELSE 0 END) as soft_rejected")
            ->selectRaw("SUM(CASE WHEN outcome = 'failed_open' THEN 1 ELSE 0 END) as failed_open")
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        $sites = Site::query()->orderBy('id')->get(['id', 'name', 'slug'])->map(function (Site $site) use ($rows): array {
            $r = $rows->get($site->id);

            return [
                'site_id'   => $site->id,
                'site_name' => $site->name,
                'site_slug' => $site->slug,
                'attempts'    => (int) ($r->attempts ?? 0),
                'api_calls'   => (int) ($r->api_calls ?? 0),
                'cache_hits'  => (int) ($r->cache_hits ?? 0),
                'skipped'     => (int) ($r->skipped ?? 0),
                'valid'       => (int) ($r->valid ?? 0),
                'risky'       => (int) ($r->risky ?? 0),
                'invalid'     => (int) ($r->invalid ?? 0),
                'allowed'       => (int) ($r->allowed ?? 0),
                'hard_rejected' => (int) ($r->hard_rejected ?? 0),
                'soft_rejected' => (int) ($r->soft_rejected ?? 0),
                'failed_open' => (int) ($r->failed_open ?? 0),
            ];
        })->values();

        return response()->json([
            'month'  => $month,
            'months' => $this->availableMonths(),
            'sites'  => $sites,
            'totals' => $this->totals($sites->all()),
            'quota'  => $this->quota(),
        ]);
    }

    /**
     * The quota widget.
     *
     * Reports BOTH our local count and SendGrid's own reported balance, because
     * they can legitimately disagree: a call billed upstream that timed out on
     * our side never becomes a billed row here. Trusting only the local number
     * is how a plan runs out while the dashboard says there is headroom.
     *
     * @return array<string, mixed>
     */
    private function quota(): array
    {
        $quota = (int) config('services.sendgrid_validation.monthly_quota', 0);
        $used = $this->sendgrid->usedThisMonth();
        $percent = $quota > 0 ? (int) floor(($used / $quota) * 100) : 0;
        $reported = $this->sendgrid->reportedRateLimit();

        return [
            'month'     => EmailValidationLog::currentQuotaMonth(),
            'quota'     => $quota,
            'used'      => $used,
            'remaining' => max(0, $quota - $used),
            'percent'   => $percent,
            // Two thresholds, as specified: a warning to act on and a hard stop.
            'warning'   => $percent >= 80 && $percent < 100,
            'exhausted' => $percent >= 100,
            // SendGrid's own numbers, when it has told us. Null until the first
            // real call of the deployment.
            'sendgrid_remaining' => $reported['remaining'] ?? null,
            'sendgrid_reset'     => $reported['reset'] ?? null,
            'enabled'   => (bool) config('services.sendgrid_validation.enabled'),
            // Whether a key is present — NEVER the key, not even a prefix.
            'key_configured' => trim((string) config('services.sendgrid_validation.key')) !== '',
            'allowed_verdicts' => array_values((array) config('services.sendgrid_validation.allowed_verdicts', [])),
        ];
    }

    /** @return list<string> */
    private function availableMonths(): array
    {
        return EmailValidationLog::query()
            ->select('quota_month')
            ->distinct()
            ->orderByDesc('quota_month')
            ->pluck('quota_month')
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sites
     * @return array<string, int>
     */
    private function totals(array $sites): array
    {
        $keys = ['attempts', 'api_calls', 'cache_hits', 'skipped', 'valid', 'risky', 'invalid', 'allowed', 'hard_rejected', 'soft_rejected', 'failed_open'];
        $out = array_fill_keys($keys, 0);

        foreach ($sites as $row) {
            foreach ($keys as $k) {
                $out[$k] += (int) $row[$k];
            }
        }

        return $out;
    }
}
