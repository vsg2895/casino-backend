<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Support\Mail\SiteSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Per-site "From" resolution for public SendGrid verification emails: the domain
 * must match the subscribing site so each domain is authenticated separately.
 */
class SiteSenderTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mail.public_from_address', ''); // per-site mode by default
        config()->set('mail.public_from_local_part', 'verify');
        config()->set('mail.public_from_domain', 'fallback.example');
        config()->set('mail.site_from_domains', []);
    }

    public function test_forced_verified_sender_applies_to_every_site(): void
    {
        // Production: one SendGrid-authenticated address for all sites.
        config()->set('mail.public_from_address', 'noreply@winpalack.com');

        [$idev] = $this->siteWithKey(['domain' => 'idevaffiliation.com']);
        [$win] = $this->siteWithKey(['domain' => 'winpalack.com']);

        $this->assertSame('noreply@winpalack.com', SiteSender::verificationAddress($idev));
        $this->assertSame('noreply@winpalack.com', SiteSender::verificationAddress($win));
    }

    public function test_from_domain_defaults_to_the_sites_own_domain(): void
    {
        [$idev] = $this->siteWithKey(['domain' => 'idevaffiliation.com']);
        [$win] = $this->siteWithKey(['domain' => 'winpalack.com']);

        $this->assertSame('verify@idevaffiliation.com', SiteSender::verificationAddress($idev));
        $this->assertSame('verify@winpalack.com', SiteSender::verificationAddress($win));
    }

    public function test_explicit_slug_override_wins_over_the_site_domain(): void
    {
        [$win] = $this->siteWithKey(['domain' => 'winpalack.com']);
        config()->set('mail.site_from_domains', [$win->slug => 'mail.winpalack.com']);

        $this->assertSame('verify@mail.winpalack.com', SiteSender::verificationAddress($win));
    }

    public function test_falls_back_to_config_domain_when_site_domain_is_blank(): void
    {
        $site = new Site(['slug' => 'nodomain', 'domain' => '']);

        $this->assertSame('verify@fallback.example', SiteSender::verificationAddress($site));
    }
}
