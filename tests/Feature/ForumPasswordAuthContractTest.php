<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendForumAccountEmail;
use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Registration, password sign-in and address confirmation for forum members:
 * the exact bodies, the session cap and the confirmation round trip.
 */
class ForumPasswordAuthContractTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendForumAccountEmail::class]);

        [$this->site, $this->key] = $this->siteWithKey(['forum_enabled' => true]);
    }

    private function postMember(string $path, array $body = [])
    {
        return $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->publicBase($this->site).'/forum/members/'.$path, $body);
    }

    private function member(string $status = ForumUser::STATUS_ACTIVE): ForumUser
    {
        $m = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Member',
            'email' => 'member@example.test', 'password' => 'secret-pass',
        ]);
        $m->forceFill(['email_verified_at' => now(), 'status' => $status])->save();

        return $m;
    }

    public function test_register_answers_with_the_link_and_records_ip_and_consent(): void
    {
        $res = $this->postMember('register', [
            'display_name' => 'New Member',
            'email' => 'new@example.test',
            'password' => 'simple',
            'website' => '',
            'marketing_consent' => true,
        ])->assertCreated();

        $this->assertSame(['registered', 'message', 'verify_url'], array_keys($res->json('data')));
        $this->assertTrue($res->json('data.registered'));
        $this->assertSame('Check your email to confirm your address before posting.', $res->json('data.message'));

        $member = ForumUser::query()->where('email', 'new@example.test')->sole();
        $this->assertStringStartsWith(
            $this->site->frontendBaseUrl().'/register/verify?member='.$member->id.'&expires=',
            $res->json('data.verify_url'),
        );
        $this->assertSame('127.0.0.1', inet_ntop($member->registration_ip));
        $this->assertNotNull($member->marketing_consent_at);
        $this->assertNull($member->email_verified_at);
        Bus::assertDispatched(
            SendForumAccountEmail::class,
            fn (SendForumAccountEmail $job): bool => $job->forumUserId === $member->id,
        );
    }

    public function test_the_emailed_link_confirms_the_address(): void
    {
        $link = $this->postMember('register', [
            'display_name' => 'New Member', 'email' => 'new@example.test', 'password' => 'simple', 'website' => '',
        ])->json('data.verify_url');
        $member = ForumUser::query()->where('email', 'new@example.test')->sole();

        $query = (string) parse_url($link, PHP_URL_QUERY);
        parse_str($query, $params);
        $signedPath = "verify?expires={$params['expires']}&signature={$params['signature']}";

        $this->postMember("{$member->id}/{$signedPath}")->assertOk()->assertExactJson(['data' => ['verified' => true]]);
        $this->assertNotNull($member->refresh()->email_verified_at);

        $this->postMember("{$member->id}/verify?expires={$params['expires']}&signature=tampered")
            ->assertForbidden()
            ->assertJsonPath('message', 'That link is invalid or has expired.');
    }

    public function test_a_wrong_password_and_an_unknown_address_answer_identically(): void
    {
        $this->member();

        foreach ([['member@example.test', 'wrong'], ['nobody@example.test', 'secret-pass']] as [$email, $password]) {
            $this->postMember('login', ['email' => $email, 'password' => $password])
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Those details do not match an account.']);
        }
    }

    public function test_a_banned_member_cannot_sign_in(): void
    {
        $this->member(ForumUser::STATUS_BANNED);

        $this->postMember('login', ['email' => 'member@example.test', 'password' => 'secret-pass'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'This account cannot sign in.']);
    }

    public function test_sign_in_answers_with_a_token_and_profile_and_caps_sessions_at_five(): void
    {
        $member = $this->member();
        foreach (range(1, 6) as $i) {
            $member->createToken("old-{$i}");
        }

        $res = $this->postMember('login', ['email' => 'member@example.test', 'password' => 'secret-pass'])->assertOk();

        $this->assertSame(['token', 'member'], array_keys($res->json('data')));
        $this->assertSame([
            'id' => $member->id,
            'display_name' => 'Member',
            'slug' => $member->slug,
            'avatar_path' => null,
            'posts_count' => 0,
            'verified' => true,
            'pre_moderated' => $member->isPreModerated(),
            'may_post_links' => $member->mayPostLinks(),
            'status' => 'active',
            'role' => 'user',
        ], $res->json('data.member'));

        $names = $member->tokens()->orderBy('id')->pluck('name')->all();
        $this->assertSame(['old-3', 'old-4', 'old-5', 'old-6', 'forum:'.$this->site->slug], $names);
        $this->assertNotNull($member->refresh()->last_seen_at);
    }
}
