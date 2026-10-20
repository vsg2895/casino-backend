<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VerifyEmailSendResource;
use App\Models\VerifyEmailSend;
use App\Support\DayWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The verify-email send history — the double opt-in confirmations.
 *
 * The last stream without a log, and the one it is least comfortable to be
 * missing: this email decides whether a subscriber is verified at all, so "we
 * never got it" had nothing to check against but the application log.
 *
 * Deliberately the same shape as the post-verification history it sits beside
 * in Email History: one row per attempt, the same filters, a dedicated COUNT
 * behind the badge, and newest first.
 *
 * Read-only except for `destroy()`. A row records something that happened, so
 * nothing here edits one — but a single row can be removed, which is what the
 * delete button in the admin list does.
 */
class VerifyEmailSendController extends Controller
{
    private const int PER_PAGE = 50;

    private const int MAX_PER_PAGE = 200;

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page') ?: self::PER_PAGE, 1), self::MAX_PER_PAGE);

        return VerifyEmailSendResource::collection(
            $this->filtered($request)
                // Four columns of the site, not the row: the screen shows a
                // name, and `sites` carries TEXT and JSON columns nothing here
                // reads.
                ->with('site:id,name,domain')
                ->newest()
                ->paginate($perPage),
        );
    }

    /**
     * The total behind the badge, and the failures within it.
     *
     * Separate from the listing for the reason every other history here has a
     * COUNT endpoint: the screen holds ONE page, so counting the rows in hand
     * would be wrong the moment there is a second one. `failed` rides along
     * because the banner reports how many confirmations did not go out, and
     * that number has to describe the filtered set rather than the page.
     */
    public function count(Request $request): JsonResponse
    {
        $base = $this->filtered($request);

        return response()->json([
            'total'  => (clone $base)->count(),
            'failed' => (clone $base)->where('status', VerifyEmailSend::STATUS_FAILED)->count(),
        ]);
    }

    /**
     * Delete one row.
     *
     * Deliberately one row and not a filtered set: the bulk operations on this
     * data are the nightly retention prune and `histories:clear`, both of which
     * say out loud how much they are about to remove. A button in a table
     * should only ever remove the thing it is next to.
     */
    public function destroy(VerifyEmailSend $verifyEmailSend): JsonResponse
    {
        $verifyEmailSend->delete();

        return response()->json(null, 204);
    }

    /**
     * The filter conditions, and nothing else. ONE definition shared by the
     * listing and the count, so the two can never disagree about what is being
     * described.
     *
     * @return Builder<VerifyEmailSend>
     */
    private function filtered(Request $request): Builder
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

        return VerifyEmailSend::query()
            ->search($request->query('search'))
            ->forSite($request->query('site_id'))
            ->withStatus($request->query('status'))
            ->sentFrom($request->query('sent_from'), $tz)
            ->sentUntil($request->query('sent_to'), $tz);
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
