<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckEmailValidationRequest;
use App\Models\EmailValidationLog;
use App\Models\Site;
use App\Services\Validation\EmailValidationDecider;
use App\Services\Validation\SendGridEmailValidationService;
use App\Support\Validation\ValidationOutcome;
use App\Support\Validation\ValidationReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validate ONE address on demand, from the admin panel.
 *
 * THIS IS THE SECOND — AND ONLY OTHER — CALLER of the validation service. The
 * first is the public subscribe gate. Everything else (warmup, promotions, the
 * post-verification promotion, imports, admin-created subscribers, SMS) must
 * never call it; see EmailValidationScopeTest, which pins that list.
 *
 * It spends a real credit from the same 2,500/month budget, so it deliberately
 * reuses the service's normal cost controls rather than bypassing them:
 *
 *   - a cached verdict is returned as-is and costs nothing (flagged as cached);
 *   - the per-address cooldown applies;
 *   - the monthly quota applies, and an exhausted quota reports that rather
 *     than pretending to have checked.
 *
 * Unlike the subscribe path there is no fail-open here: nothing is being gated,
 * so "we could not check" is simply reported to the operator as what it is.
 */
class EmailValidationCheckController extends Controller
{
    public function __construct(
        private readonly SendGridEmailValidationService $sendgrid,
        private readonly EmailValidationDecider $decider,
    ) {}

    public function store(CheckEmailValidationRequest $request): JsonResponse
    {
        /** @var Site $site */
        $site = Site::findOrFail($request->integer('site_id'));
        $email = (string) $request->validated('email');

        $result = $this->sendgrid->validate($email, $site->slug);

        // What the SUBSCRIBE flow would do with this address — decided by the
        // SAME decider the public path uses, so this tool can never disagree
        // with production. That is the whole point of it.
        $decision = $result->hasVerdict()
            ? $this->decider->decide($result->toResultArray())
            : null;

        $outcome = $decision?->outcome ?? ValidationOutcome::FailedOpen;
        $wouldAllow = $decision === null ? null : $decision->outcome === ValidationOutcome::Allowed;

        // Fall back to the skip/failure reason: with no verdict there is no
        // decision, and writing null here would lose the one thing the row is
        // for — why nothing was checked.
        $reason = $decision?->reason ?? $result->skipReason
            ?? ($result->errorMessage !== null ? ValidationReason::TRANSPORT_ERROR : null);

        $this->log($site, $email, $result, $outcome->value, $reason);

        return response()->json([
            'email'   => $email,
            'site'    => ['id' => $site->id, 'name' => $site->name, 'slug' => $site->slug],
            'checked' => $result->hasVerdict(),
            'verdict' => $result->verdict,
            'score'   => $result->score,
            'checks'  => $result->checks,
            'suggestion' => $result->suggestion,
            // Null when nothing could be checked — deliberately tri-state, so
            // the UI never renders "would be blocked" for an address that was
            // simply never looked at.
            'would_allow' => $wouldAllow,
            'outcome'     => $outcome->value,
            // The rule that decided it — the same machine-readable code the log
            // and its filters use, so the operator can jump straight from a
            // spot-check to "show me every other address this rule refused".
            'reason_code' => $reason,
            'was_cached'  => $result->wasCached,
            'was_skipped' => $result->wasSkipped,
            'skip_reason' => $result->skipReason,
            'http_status' => $result->httpStatus,
            // Safe to show an admin: the service already strips anything
            // key-shaped out of this string before it is stored or returned.
            'error_message'    => $result->errorMessage,
            'latency_ms'       => $result->latencyMs,
            'allowed_verdicts' => array_values((array) config('services.sendgrid_validation.allowed_verdicts', [])),
        ]);
    }

    /**
     * Record it like any other attempt.
     *
     * `source` is `admin_check_<slug>`, not `subscribe_<slug>`, so a manual test
     * is distinguishable from real traffic in both our log and SendGrid's own
     * dashboard — otherwise an operator testing addresses would quietly inflate
     * a site's apparent signup validation numbers.
     */
    private function log(Site $site, string $email, $result, string $outcome, ?string $reason): void
    {
        $checks = $result->checks ?? [];
        $flag = static fn (string $g, string $n): ?bool => is_bool($checks[$g][$n] ?? null) ? $checks[$g][$n] : null;

        try {
            EmailValidationLog::create([
                'site_id'     => $site->id,
                'email'       => $email,
                'source'      => 'admin_check_' . $site->slug,
                'outcome'     => $outcome,
                'reason_code' => $reason,
                'quota_month' => EmailValidationLog::currentQuotaMonth(),

                'verdict'    => $result->verdict,
                'score'      => $result->score,
                'suggestion' => $result->suggestion,

                'has_valid_address_syntax'        => $flag('domain', 'has_valid_address_syntax'),
                'has_mx_or_a_record'              => $flag('domain', 'has_mx_or_a_record'),
                'is_suspected_disposable_address' => $flag('domain', 'is_suspected_disposable_address'),
                'is_suspected_role_address'       => $flag('local_part', 'is_suspected_role_address'),
                'has_known_bounces'               => $flag('additional', 'has_known_bounces'),
                'has_suspected_bounces'           => $flag('additional', 'has_suspected_bounces'),

                'raw_checks'    => $result->checks,
                'was_cached'    => $result->wasCached,
                'http_status'   => $result->httpStatus,
                'error_message' => $result->errorMessage,
                'latency_ms'    => $result->latencyMs,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to write admin email validation log', ['error' => $e->getMessage()]);
        }
    }
}
