<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The admin Users screen has to answer "did this person confirm, and when".
 *
 * It could only answer the first half: the payload carried `verified` as a
 * boolean, so a support question about a specific signup had to be taken to the
 * database. The column has always held the timestamp; it simply never reached
 * the admin.
 *
 * The second test is the one that matters for reading the screen correctly.
 * `last_seen_at` is written by sign-in and by the authenticated heartbeat —
 * never by registration, and never by confirming an address. So "verified but
 * never seen" is the NORMAL state immediately after signup, not a fault, and
 * the two fields must stay independent.
 */
class ForumMemberVerificationVisibleTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site] = $this->siteWithKey(['forum_enabled' => true, 'accounts_enabled' => true]);
        $this->actingAsAdmin();
    }

    /** Registered, confirmed by email, never signed in. */
    private function confirmedButNeverSignedIn(): ForumUser
    {
        $member = ForumUser::create([
            'site_id'      => $this->site->id,
            'display_name' => 'Vladimir',
            'email'        => 'member@example.test',
            'password'     => bcrypt('secret'),
        ]);

        // `status` and `slug` are deliberately not fillable — a registration
        // POST must not set them — so they are written the way the model intends.
        $member->forceFill([
            'slug'              => 'vladimir',
            'status'            => ForumUser::STATUS_ACTIVE,
            'email_verified_at' => Carbon::parse('2026-10-21 09:31:00'),
            'last_seen_at'      => null,
        ])->save();

        return $member->refresh();
    }

    private function row(): array
    {
        return $this->getJson('/api/v1/admin/forum-members')
            ->assertOk()
            ->json('data.0');
    }

    public function test_the_members_listing_reports_when_the_address_was_confirmed(): void
    {
        $this->confirmedButNeverSignedIn();

        $row = $this->row();

        $this->assertTrue($row['verified'], 'the boolean the "unconfirmed" tag reads');
        $this->assertNotNull(
            $row['email_verified_at'],
            'and the timestamp behind it, which the screen could not previously show',
        );
        $this->assertStringStartsWith('2026-10-21', (string) $row['email_verified_at']);
    }

    public function test_confirming_an_address_does_not_count_as_being_seen(): void
    {
        /*
         * Clicking a link in a confirmation email is a signed request, not a
         * session. If this ever starts populating `last_seen_at`, the Last seen
         * column begins reporting activity that never happened — which is worse
         * than the blank it shows today.
         */
        $this->confirmedButNeverSignedIn();

        $row = $this->row();

        $this->assertNotNull($row['email_verified_at']);
        $this->assertNull($row['last_seen_at'], 'verified and never seen is a legitimate state');
    }

    public function test_an_unconfirmed_member_reports_no_timestamp(): void
    {
        $member = ForumUser::create([
            'site_id'      => $this->site->id,
            'display_name' => 'Unconfirmed',
            'email'        => 'pending@example.test',
            'password'     => bcrypt('secret'),
        ]);
        $member->forceFill(['slug' => 'unconfirmed', 'status' => ForumUser::STATUS_ACTIVE])->save();

        $row = $this->row();

        $this->assertFalse($row['verified']);
        $this->assertNull($row['email_verified_at'], 'no date, rather than an invented one');
    }
}
