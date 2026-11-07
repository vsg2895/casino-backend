<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\CampaignReceiver;
use App\Models\MailgunReceiver;
use App\Models\MailgunSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * One-click unsubscribe for Mailgun receivers. Keyless — the token IS the
 * credential — and outside `verify.site`, because a mail provider posting an
 * RFC 8058 one-click sends neither the site slug nor the site key.
 *
 * TWO routes, deliberately, and this is the important design point:
 *
 *  - POST is the List-Unsubscribe-Post target. It acts immediately, because
 *    that is what the RFC specifies and what Gmail/Apple invoke.
 *  - GET is the visible in-body link. It renders a CONFIRM PAGE and changes
 *    nothing, because mail clients, link scanners and security appliances
 *    prefetch GET links — an acting GET would silently unsubscribe people who
 *    never clicked. The project already made this exact decision for the
 *    newsletter unsubscribe; see routes/api.php.
 *
 * So the visible link is one click for a human and inert for a robot.
 *
 * {@see SendgridUnsubscribeController} is the same flow for the SendGrid list;
 * it differs only in {@see receiverModel()}, which names the table the token
 * is looked up in. The pages and the suppression write are shared.
 */
class MailgunUnsubscribeController extends Controller
{
    /** RFC 8058 one-click target. Acts immediately; always 200 to the provider. */
    public function oneClick(string $token): Response
    {
        $this->unsubscribe($token);

        // Never leak whether a token was real: a 404 here would turn this
        // endpoint into an oracle for probing valid tokens.
        return response('', Response::HTTP_OK);
    }

    /** Human-facing confirmation page. Renders only — never acts. */
    public function show(string $token): Response
    {
        $receiver = $this->findReceiver($token);

        return response()->view('unsubscribe.mailgun-confirm', [
            'email'      => $receiver?->email,
            'alreadyOff' => $receiver?->unsubscribed_at !== null,
            // Built here rather than in the view, so the page posts back to
            // the list the token belongs to.
            'confirmUrl' => url('/api/v1/' . $this->receiverModel()::unsubscribePath() . '/' . $token . '/confirm'),
        ]);
    }

    /** Target of the confirm page's form. */
    public function confirm(Request $request, string $token): Response
    {
        $done = $this->unsubscribe($token);

        return response()->view('unsubscribe.mailgun-done', ['done' => $done]);
    }

    /**
     * Deactivate the receiver and add the address to the shared suppression
     * list, in one transaction.
     *
     * Both halves matter: deactivating alone would let a re-import resurrect the
     * person, and suppressing alone would leave them active in the UI. Wrapped
     * so a failure between the two cannot leave that split state.
     */
    private function unsubscribe(string $token): bool
    {
        $receiver = $this->findReceiver($token);

        if ($receiver === null) {
            return false;
        }

        DB::transaction(static function () use ($receiver): void {
            if ($receiver->unsubscribed_at === null) {
                $receiver->forceFill([
                    'unsubscribed_at' => now(),
                    'is_active'       => false,
                ])->save();
            }

            MailgunSuppression::suppress(
                (string) $receiver->email,
                MailgunSuppression::REASON_UNSUBSCRIBE,
                'One-click unsubscribe',
            );
        });

        return true;
    }

    /**
     * The list whose tokens this controller resolves.
     *
     * @return class-string<CampaignReceiver>
     */
    protected function receiverModel(): string
    {
        return MailgunReceiver::class;
    }

    private function findReceiver(string $token): ?CampaignReceiver
    {
        $model = $this->receiverModel();

        return $model::where('unsubscribe_token', $token)->first();
    }
}
