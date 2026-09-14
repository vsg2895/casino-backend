<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailValidationLog;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The admin log + analysis surface.
 *
 * These are the queries the operator uses to decide whether to loosen the rules,
 * so a filter that quietly ignores a parameter is worse than one that errors:
 * it produces a confident, wrong answer and the thresholds get changed on it.
 */
class EmailValidationLogAdminTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->site] = $this->siteWithKey();
        $this->actingAsAdmin();
    }

    /** @param array<string, mixed> $attrs */
    private function log(array $attrs = []): EmailValidationLog
    {
        return EmailValidationLog::create([
            'site_id'     => $this->site->id,
            'email'       => 'someone@example.com',
            'source'      => 'subscribe_' . $this->site->slug,
            'outcome'     => 'allowed',
            'verdict'     => 'Valid',
            'score'       => 0.9,
            'was_cached'  => false,
            'quota_month' => EmailValidationLog::currentQuotaMonth(),
            'has_valid_address_syntax'        => true,
            'has_mx_or_a_record'              => true,
            'is_suspected_disposable_address' => false,
            'is_suspected_role_address'       => false,
            'has_known_bounces'               => false,
            'has_suspected_bounces'           => false,
            ...$attrs,
        ]);
    }

    /**
     * Total for a filter set.
     *
     * NOT named count(): PHPUnit\Framework\TestCase::count() is final, and
     * overriding it is a fatal error at class-load time.
     */
    private function totalFor(array $params): int
    {
        return (int) $this->getJson('/api/v1/admin/email-validation/count?' . http_build_query($params))
            ->assertOk()->json('total');
    }

    // ── THE query the screen exists for ──────────────────────────────────────

    public function test_the_tuning_query_is_expressible(): void
    {
        // "Risky, not disposable, but a role address" — if these turn out to be
        // real mailboxes on real company domains, the answer is to widen
        // ALLOWED_VERDICTS. Getting this wrong changes a policy decision.
        $wanted = $this->log([
            'email' => 'info@acme-corp.co', 'verdict' => 'Risky', 'outcome' => 'soft_rejected',
            'reason_code' => 'verdict_not_allowed',
            'is_suspected_role_address' => true, 'is_suspected_disposable_address' => false,
        ]);
        // A disposable Risky role address — must NOT match.
        $this->log([
            'email' => 'x@tempmail.io', 'verdict' => 'Risky', 'outcome' => 'soft_rejected',
            'is_suspected_role_address' => true, 'is_suspected_disposable_address' => true,
        ]);
        // A Valid role address — must NOT match.
        $this->log(['email' => 'info@gmail.com', 'is_suspected_role_address' => true]);

        $params = [
            'verdict' => 'Risky',
            'is_suspected_disposable_address' => 0,
            'is_suspected_role_address' => 1,
        ];

        $this->assertSame(1, $this->totalFor($params));
        $this->getJson('/api/v1/admin/email-validation?' . http_build_query($params))
            ->assertOk()->assertJsonPath('data.0.email', $wanted->email);
    }

    public function test_each_check_filters_independently_and_tri_state(): void
    {
        $this->log(['email' => 'a@x.test', 'has_known_bounces' => true]);
        $this->log(['email' => 'b@x.test', 'has_known_bounces' => false]);

        $this->assertSame(1, $this->totalFor(['has_known_bounces' => 1]));
        $this->assertSame(1, $this->totalFor(['has_known_bounces' => 0]));
        // Absent == "any": both rows.
        $this->assertSame(2, $this->totalFor([]));
    }

    // ── the other filters ────────────────────────────────────────────────────

    public function test_score_range_is_inclusive(): void
    {
        $this->log(['email' => 'low@x.test', 'score' => 0.4]);
        $this->log(['email' => 'mid@x.test', 'score' => 0.7]);
        $this->log(['email' => 'high@x.test', 'score' => 0.95]);

        $this->assertSame(2, $this->totalFor(['min_score' => 0.7]));
        $this->assertSame(2, $this->totalFor(['max_score' => 0.7]));
        $this->assertSame(1, $this->totalFor(['min_score' => 0.7, 'max_score' => 0.8]));
    }

    public function test_the_three_search_modes_differ(): void
    {
        $this->log(['email' => 'user@gmail.com']);
        $this->log(['email' => 'user@mailinator.com']);
        $this->log(['email' => 'mailbox@other.test']);

        // contains matches anywhere…
        $this->assertSame(3, $this->totalFor(['search' => 'mail', 'search_mode' => 'contains']));
        // …domain is anchored to the part after @, so "mail" matches no domain…
        $this->assertSame(0, $this->totalFor(['search' => 'mail', 'search_mode' => 'domain']));
        $this->assertSame(1, $this->totalFor(['search' => 'gmail.com', 'search_mode' => 'domain']));
        // …and exact is exact.
        $this->assertSame(1, $this->totalFor(['search' => 'user@gmail.com', 'search_mode' => 'exact']));
        $this->assertSame(0, $this->totalFor(['search' => 'user@gmail.co', 'search_mode' => 'exact']));
    }

    public function test_like_metacharacters_in_a_search_are_literal(): void
    {
        $this->log(['email' => 'a_b@x.test']);
        $this->log(['email' => 'axb@x.test']);

        // Unescaped, "a_b" would match both — "_" is a single-character wildcard.
        $this->assertSame(1, $this->totalFor(['search' => 'a_b', 'search_mode' => 'contains']));
    }

    public function test_filters_by_outcome_reason_and_cached(): void
    {
        $this->log(['email' => 'h@x.test', 'outcome' => 'hard_rejected', 'reason_code' => 'bad_syntax']);
        $this->log(['email' => 's@x.test', 'outcome' => 'soft_rejected', 'reason_code' => 'low_score']);
        $this->log(['email' => 'c@x.test', 'was_cached' => true]);

        $this->assertSame(1, $this->totalFor(['outcome' => 'hard_rejected']));
        $this->assertSame(1, $this->totalFor(['reason_code' => 'low_score']));
        $this->assertSame(1, $this->totalFor(['cached' => 1]));
        $this->assertSame(2, $this->totalFor(['cached' => 0]));
    }

    public function test_another_sites_rows_are_filterable_out(): void
    {
        [$other] = $this->siteWithKey();
        $this->log(['email' => 'mine@x.test']);
        $this->log(['email' => 'theirs@x.test', 'site_id' => $other->id]);

        $this->assertSame(1, $this->totalFor(['site_id' => $this->site->id]));
    }

    // ── sorting ──────────────────────────────────────────────────────────────

    public function test_it_sorts_by_score_in_both_directions(): void
    {
        $this->log(['email' => 'lo@x.test', 'score' => 0.1]);
        $this->log(['email' => 'hi@x.test', 'score' => 0.99]);

        $this->getJson('/api/v1/admin/email-validation?sort=score&direction=desc')
            ->assertOk()->assertJsonPath('data.0.email', 'hi@x.test');
        $this->getJson('/api/v1/admin/email-validation?sort=score&direction=asc')
            ->assertOk()->assertJsonPath('data.0.email', 'lo@x.test');
    }

    public function test_an_unknown_sort_column_falls_back_instead_of_reaching_sql(): void
    {
        $this->log();

        // The parameter is allow-listed; anything else must not become SQL.
        $this->getJson('/api/v1/admin/email-validation?sort=' . urlencode('score; DROP TABLE users'))
            ->assertOk();
        $this->assertDatabaseCount('email_validation_logs', 1);
    }

    // ── the analysis panel ───────────────────────────────────────────────────

    public function test_the_analysis_aggregates_respect_the_filters(): void
    {
        $this->log(['email' => 'v@x.test', 'verdict' => 'Valid', 'score' => 0.9]);
        $this->log(['email' => 'r1@x.test', 'verdict' => 'Risky', 'score' => 0.5, 'outcome' => 'soft_rejected', 'reason_code' => 'verdict_not_allowed']);
        $this->log(['email' => 'r2@x.test', 'verdict' => 'Risky', 'score' => 0.55, 'outcome' => 'soft_rejected', 'reason_code' => 'verdict_not_allowed']);

        $all = $this->getJson('/api/v1/admin/email-validation/analysis')->assertOk();
        $all->assertJsonPath('total', 3);
        $all->assertJsonPath('verdicts.Risky', 2);

        // Filtered: the panel must describe the FILTERED set, or it would
        // silently answer a different question than the table below it.
        $risky = $this->getJson('/api/v1/admin/email-validation/analysis?verdict=Risky')->assertOk();
        $risky->assertJsonPath('total', 2);
        $risky->assertJsonPath('reasons.verdict_not_allowed', 2);
        $this->assertArrayNotHasKey('Valid', $risky->json('verdicts'));
    }

    public function test_the_score_histogram_buckets_by_tenths(): void
    {
        $this->log(['email' => 'a@x.test', 'score' => 0.05]);
        $this->log(['email' => 'b@x.test', 'score' => 0.72]);
        $this->log(['email' => 'c@x.test', 'score' => 0.79]);

        $h = $this->getJson('/api/v1/admin/email-validation/analysis')->assertOk()->json('score_histogram');

        $this->assertSame(1, $h['0']);
        $this->assertSame(2, $h['7']);
    }

    public function test_check_counts_are_real_totals_not_booleans(): void
    {
        // The model casts these six to boolean, so a SUM() aliased back onto the
        // column name comes through the cast as `true` and every count collapses
        // to 1. That bug shipped once; this is the guard.
        foreach (range(1, 3) as $i) {
            $this->log(['email' => "r{$i}@x.test", 'is_suspected_role_address' => true]);
        }

        $checks = $this->getJson('/api/v1/admin/email-validation/analysis')->assertOk()->json('checks');

        $this->assertSame(3, $checks['is_suspected_role_address']);
        $this->assertSame(0, $checks['has_known_bounces']);
    }

    public function test_top_rejected_domains_counts_only_rejections(): void
    {
        $this->log(['email' => 'ok@good.test', 'outcome' => 'allowed']);
        $this->log(['email' => 'a@bad.test', 'outcome' => 'hard_rejected']);
        $this->log(['email' => 'b@bad.test', 'outcome' => 'soft_rejected']);

        $domains = $this->getJson('/api/v1/admin/email-validation/analysis')->assertOk()->json('top_rejected_domains');

        $this->assertSame(2, $domains['bad.test']);
        $this->assertArrayNotHasKey('good.test', $domains);
    }

    // ── export + read-only + auth ────────────────────────────────────────────

    public function test_the_csv_export_respects_the_current_filters(): void
    {
        $this->log(['email' => 'keep@x.test', 'verdict' => 'Risky', 'outcome' => 'soft_rejected']);
        $this->log(['email' => 'drop@x.test', 'verdict' => 'Valid']);

        $csv = $this->get('/api/v1/admin/email-validation/export?verdict=Risky')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('keep@x.test', $csv);
        $this->assertStringNotContainsString('drop@x.test', $csv);
        // The six checks travel with it, or the export cannot answer the same
        // questions the screen does.
        $this->assertStringContainsString('Disposable', $csv);
        $this->assertStringContainsString('Role', $csv);
    }

    public function test_the_screen_is_read_only(): void
    {
        $log = $this->log();

        // No write verbs exist for this resource, by design: a re-run button on
        // an audit screen would spend a paid credit.
        // The property is that no write endpoint exists — not which rejection
        // Laravel picks. POST gets 405 (the URI has a GET route), the /{id}
        // verbs get 404 (no route matches that URI at all). Both are refusals.
        foreach ([
            $this->postJson('/api/v1/admin/email-validation', []),
            $this->putJson("/api/v1/admin/email-validation/{$log->id}", []),
            $this->deleteJson("/api/v1/admin/email-validation/{$log->id}"),
        ] as $response) {
            $this->assertTrue(
                $response->isClientError(),
                'a write verb returned ' . $response->getStatusCode() . ' instead of being refused',
            );
        }

        $this->assertDatabaseCount('email_validation_logs', 1);
    }

    public function test_every_endpoint_requires_authentication(): void
    {
        app()['auth']->forgetGuards();

        $this->getJson('/api/v1/admin/email-validation')->assertUnauthorized();
        $this->getJson('/api/v1/admin/email-validation/count')->assertUnauthorized();
        $this->getJson('/api/v1/admin/email-validation/analysis')->assertUnauthorized();
        $this->getJson('/api/v1/admin/email-validation/export')->assertUnauthorized();
    }
}
