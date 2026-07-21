<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Purpose-specific Mailers — two transports
    |--------------------------------------------------------------------------
    |
    | The platform sends two very different kinds of mail and routes each
    | through its own transport:
    |
    |  - "admin_mailer"   Everything triggered from the admin panel: the "Send
    |                     test" buttons (subscription / verify / promotion) AND
    |                     the real promotion campaigns (scheduler + queued
    |                     batches). Routed through the .env MAIL_* SMTP config so
    |                     it goes out from the operator's own mail server.
    |                     (Back-compat: honours the old MAIL_TEST_MAILER.)
    |
    |  - "public_mailer"  Verification emails sent when a VISITOR subscribes on a
    |                     public site (modal / form). Routed through the native
    |                     SendGrid Web API transport (SENDGRID_API_KEY) with a
    |                     per-site From domain (see below).
    |                     (Back-compat: honours the old MAIL_NEWSLETTER_MAILER.)
    |
    */

    'admin_mailer' => env('MAIL_ADMIN_MAILER', env('MAIL_TEST_MAILER', env('MAIL_MAILER', 'smtp'))),

    'public_mailer' => env('MAIL_PUBLIC_MAILER', env('MAIL_NEWSLETTER_MAILER', 'sendgrid')),

    /*
    | "From" address for public (SendGrid) verification emails.
    |
    | Current production reality: only ONE domain is authenticated in SendGrid
    | (winpalack.com), so ALL verification emails — no matter which site the
    | visitor subscribed on — must be sent FROM that single verified address so
    | they pass SPF/DKIM and actually deliver. Set MAIL_PUBLIC_FROM_ADDRESS to
    | that verified mailbox (e.g. noreply@winpalack.com). The display NAME still
    | reflects the subscribing site (the template's from_name), and the links in
    | the email body still point at that site's own real domain.
    |
    | Per-site fallback (for later, once each domain is authenticated in SendGrid
    | separately): leave MAIL_PUBLIC_FROM_ADDRESS empty and the From domain is
    | resolved dynamically per site (see App\Support\Mail\SiteSender):
    |   1. an explicit MAIL_SITE_FROM_DOMAINS override for the site's slug
    |   2. the site's own registered `domain`
    |   3. the MAIL_PUBLIC_FROM_DOMAIN fallback
    */
    'public_from_address' => env('MAIL_PUBLIC_FROM_ADDRESS', 'noreply@winpalack.com'),

    'public_from_local_part' => env('MAIL_PUBLIC_FROM_LOCAL_PART', 'noreply'),

    'public_from_domain' => env('MAIL_PUBLIC_FROM_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'example.com'),

    // Optional explicit slug→domain overrides, e.g.
    // MAIL_SITE_FROM_DOMAINS="idevaffiliation:idevaffiliation.com,winpalack:winpalack.com"
    'site_from_domains' => collect(explode(',', (string) env('MAIL_SITE_FROM_DOMAINS', '')))
        ->filter()
        ->mapWithKeys(function (string $pair): array {
            [$slug, $domain] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            return $slug !== '' && $domain !== '' ? [$slug => $domain] : [];
        })
        ->all(),

    // Double opt-in is ON for EVERY site: a new subscriber starts unverified
    // (pending) and only becomes verified when they click the emailed link.
    // This list is the OPT-OUT — slugs here skip verification and are auto-
    // verified on subscribe. Empty by default, so all sites require the click.
    // Comma-separated env override (MAIL_AUTO_VERIFY_SLUGS).
    'auto_verify_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MAIL_AUTO_VERIFY_SLUGS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        /*
         * Native SendGrid Web API transport (NOT the SMTP relay). Sends
         * subscription/newsletter emails for ALL sites via the SendGrid HTTP API
         * using the API key directly. The `sendgrid` transport is registered in
         * AppServiceProvider::boot() via the symfony/sendgrid-mailer bridge.
         * Isolated from the default mailer so admin "send test" mail (SMTP) is
         * unaffected.
         */
        'sendgrid' => [
            'transport' => 'sendgrid',
            'key' => env('SENDGRID_API_KEY'),
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
