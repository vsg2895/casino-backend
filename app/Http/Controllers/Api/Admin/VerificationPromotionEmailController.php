<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\SendsAdminTestEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateVerificationPromotionEmailRequest;
use App\Http\Requests\Admin\UpdateVerificationPromotionOverrideRequest;
use App\Http\Resources\VerificationPromotionEmailResource;
use App\Http\Resources\VerificationPromotionSendResource;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionSend;
use App\Services\PostVerificationPromotionEmailService;
use App\Services\PostVerificationPromotionTestSender;
use App\Services\VerificationPromotionOverrideService;
use App\Support\DayWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin management of the ONE global post-verification promotion.
 *
 * Deliberately has no {site} parameter anywhere: a single template serves
 * subscribers from every registered site, and since the branding is pinned to
 * Winpalack in config('promotions.after_verification') there is no longer
 * anything for a site to resolve either — the picker this page used to carry is
 * gone. Otherwise, it follows
 * {@see SitePromotionEmailController} — show (materialising defaults on first
 * access), update, live preview of unsaved edits, and a test send.
 *
 * The test send deliberately does NOT use
 * {@see SendsAdminTestEmail}; it mirrors the
 * real send instead. See {@see PostVerificationPromotionTestSender}.
 */
class VerificationPromotionEmailController extends Controller
{
    public function __construct(private readonly PostVerificationPromotionEmailService $promotions) {}

    /** Rows per page in the history listing. */
    private const int HISTORY_PER_PAGE = 25;

    /** Ceiling on a caller-supplied page size. */
    private const int HISTORY_MAX_PER_PAGE = 200;

    /**
     * Every post-verification promotion this platform has sent, newest first.
     *
     * The same shape as the Warmup History screen, because it answers the same
     * question: one row per attempt — address, site, outcome, when — filtered by
     * site, status, address prefix and date range.
     *
     * It reads {@see VerificationPromotionSend}, this stream's own log, rather
     * than `promotion_email_histories`. That shared table is where the 24-hour
     * dedup looks and must keep receiving these sends, but it carries no stream
     * column and merges a campaign send and a post-verification send that reach
     * one address on one day — so it cannot answer "what did THIS stream send".
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page') ?: self::HISTORY_PER_PAGE, 1),
            self::HISTORY_MAX_PER_PAGE,
        );

        return VerificationPromotionSendResource::collection(
            $this->filteredHistory($request)
                ->with('site:id,name,domain')
                ->newest()
                ->simplePaginate($perPage),
        );
    }

    /**
     * Totals for the current filters, as a dedicated COUNT.
     *
     * Separate from the listing for the same reason every other section does it:
     * the screen only ever holds one page, so counting the rows in hand would be
     * wrong the moment there is a second one. `failed` rides along because the
     * banner above the table reports how many attempts did not arrive, and that
     * number must describe the filtered set rather than the page.
     */
    public function historyCount(Request $request): JsonResponse
    {
        $base = $this->filteredHistory($request);

        return response()->json([
            'total' => (clone $base)->count(),
            'failed' => (clone $base)->where('status', VerificationPromotionSend::STATUS_FAILED)->count(),
        ]);
    }

    /** Delete one row of this stream's history. */
    public function destroyHistory(VerificationPromotionSend $send): JsonResponse
    {
        $send->delete();

        return response()->json(null, 204);
    }

    /**
     * The history filter conditions, and nothing else. Shared by the listing and
     * the count so the two can never disagree about what is being described.
     *
     * @return Builder<VerificationPromotionSend>
     */
    private function filteredHistory(Request $request): Builder
    {
        /*
         * The operator's own IANA timezone, sent by the browser.
         *
         * `sent_from`/`sent_to` are CALENDAR DAYS picked from a local calendar
         * and `sent_at` is stored in UTC, so the zone is what decides where the
         * operator's day starts. Absent falls back to UTC — exactly what this
         * filter did before. See App\Support\DayWindow.
         *
         * Read straight off the query string rather than validated: this is a
         * read-only history listing whose other filters are deliberately
         * forgiving of a stale bookmark, and DayWindow ignores a zone it does
         * not recognise rather than throwing.
         */
        $tz = $this->filterTimezone($request);

        return VerificationPromotionSend::query()
            ->search($request->query('search'))
            ->forSite($request->query('site_id'))
            ->withStatus($request->query('status'))
            ->sentFrom($request->query('sent_from'), $tz)
            ->sentUntil($request->query('sent_to'), $tz);
    }

    /** The global template + settings, created with defaults the first time. */
    public function show(): JsonResponse
    {
        return response()->json(
            new VerificationPromotionEmailResource(VerificationPromotionEmail::current()),
        );
    }

    /** Persist edits to the global template + settings. */
    public function update(UpdateVerificationPromotionEmailRequest $request): JsonResponse
    {
        $config = VerificationPromotionEmail::current();
        $config->update($request->validated());

        return response()->json(
            new VerificationPromotionEmailResource($config->refresh()),
        );
    }

    /**
     * Render the (possibly unsaved) template to HTML for the live preview.
     *
     * There is no "preview as <site>" any more: the branding is fixed to
     * Winpalack in config, so every site would produce the same pixels. The
     * preview is therefore always what a subscriber receives, whichever of the
     * six they came from.
     *
     * A Site is still passed down, for the unsubscribe link alone — the preview
     * shows a realistic one rather than a dead string.
     */
    public function preview(UpdateVerificationPromotionEmailRequest $request): JsonResponse
    {
        $site = $this->promotions->previewSite();

        if ($site === null) {
            return response()->json([
                'message' => 'Register a site before previewing — the unsubscribe link is built from one.',
            ], 422);
        }

        $template = new VerificationPromotionEmail($request->validated());

        return response()->json([
            'html' => $this->promotions->previewMail($site, $template)->render(),
        ]);
    }

    /**
     * Per-site image + link overrides, one entry per registered site.
     *
     * Returns a row for EVERY site, including sites with nothing set, so the
     * admin screen can render the full list without inventing placeholders and
     * without the operator having to create a row before editing one.
     */
    public function overrides(VerificationPromotionOverrideService $overrides): JsonResponse
    {
        return response()->json($overrides->listing());
    }

    /**
     * Save one site's overrides.
     *
     * An upsert, because the screen edits sites rather than rows: the operator
     * never creates an override, they fill one in. Clearing every field leaves
     * a row of nulls, which `overrides()` reads as "nothing set" and the send
     * path ignores — so clearing the form restores the default without the
     * operator having to find a delete button.
     */
    public function updateOverride(
        UpdateVerificationPromotionOverrideRequest $request,
        Site $site,
        VerificationPromotionOverrideService $overrides,
    ): JsonResponse {
        $row = $overrides->save($site, $request->validated(), $request->has('footer_link_urls'));

        return response()->json([
            'data' => [
                'site_id' => (int) $site->id,
                'active' => $row->overrides() !== [],
            ],
        ]);
    }

    /**
     * Send a one-off test of the SAVED template through the SAVED transport.
     *
     * Uses the configured provider/credential rather than SMTP, so a green
     * result here means the real send path works. See
     * {@see PostVerificationPromotionTestSender}.
     */
    public function sendTest(SendTestSiteEmailRequest $request, PostVerificationPromotionTestSender $tester): JsonResponse
    {
        $siteId = $request->validated('site_id');

        $result = $tester->send(
            VerificationPromotionEmail::current(),
            $siteId === null ? null : (int) $siteId,
            $request->validated('to'),
            $request->validated('name'),
        );

        return response()->json(
            ['ok' => $result->ok, 'message' => $result->message],
            match (true) {
                $result->ok => 200,
                $result->attempted => 502,
                default => 422,
            },
        );
    }

    /**
     * The caller's IANA timezone, or null.
     *
     * Null for anything unrecognised, which DayWindow then reads as UTC — the
     * behaviour every one of these filters had before it learned about zones.
     */
    private function filterTimezone(Request $request): ?string
    {
        $tz = $request->query('tz');

        return (is_string($tz) && DayWindow::isValidTimezone($tz)) ? $tz : null;
    }
}
