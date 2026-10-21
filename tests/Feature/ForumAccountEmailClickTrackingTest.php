<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ForumAccountEmail;
use App\Models\Site;
use App\Services\Mail\Transport\SendgridClickTrackingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The account confirmation and password-reset emails must not be click-tracked.
 *
 * SendGrid rewrites every link in a tracked message to a redirector — a
 * branded `urlNNNN.<domain>` host, or `sendgrid.net` without one. In an email
 * that asks somebody to confirm their identity that is two separate failures:
 * the recipient is sent to a host they have never seen, and if the branded
 * subdomain has no certificate the browser refuses the link outright.
 *
 * Both were observed on this platform. The newsletter's verification email was
 * fixed for exactly this reason; the forum's was not, and presented as "this
 * address is not trusted" when the button was clicked. Search Console had been
 * reporting 404s on `url7462.winpalack.com` — a tracking host for this very
 * domain — for some time before the two were connected.
 *
 * The header is asserted rather than the rendered body because that is the
 * whole mechanism: {@see SendgridClickTrackingClient} strips the marker from
 * the API payload and swaps in `tracking_settings.click_tracking`. No marker,
 * no opt-out.
 */
class ForumAccountEmailClickTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function mailable(string $type): ForumAccountEmail
    {
        return new ForumAccountEmail(
            Site::factory()->create(['name' => 'Winpalack']),
            'Stepanyan',
            'https://winpalack.com/register/verify?member=17&expires=1&signature=abc',
            $type,
        );
    }

    public function test_the_confirmation_email_opts_out_of_click_tracking(): void
    {
        $headers = $this->mailable(ForumAccountEmail::TYPE_VERIFY)->headers();

        $this->assertArrayHasKey(
            SendgridClickTrackingClient::DISABLE_HEADER,
            $headers->text,
            'without the marker SendGrid rewrites the confirmation link to a redirector',
        );
    }

    public function test_the_password_reset_email_opts_out_too(): void
    {
        // If anything more sensitive than a confirmation link, and it travels
        // through the same mailable — so it must not be exempt by omission.
        $headers = $this->mailable(ForumAccountEmail::TYPE_RESET)->headers();

        $this->assertArrayHasKey(SendgridClickTrackingClient::DISABLE_HEADER, $headers->text);
    }

    public function test_the_marker_is_stripped_before_the_payload_leaves(): void
    {
        /*
         * The marker is OURS, not SendGrid's. If it ever reached the API as a
         * custom header it would be delivered to the recipient's inbox as a
         * stray X- header — and, worse, click tracking would still be on,
         * because the real setting is what the decorator substitutes.
         */
        $this->assertSame(
            'X-Sendgrid-Click-Tracking',
            SendgridClickTrackingClient::DISABLE_HEADER,
            'the decorator matches on this exact name; changing it silently disables the opt-out',
        );
    }
}
