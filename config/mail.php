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
    | Purpose-specific Mailers
    |--------------------------------------------------------------------------
    |
    | The platform sends two very different kinds of mail and routes each
    | through its own transport:
    |
    |  - "test_mailer"       Admin-panel "Send test" buttons (subscription &
    |                        promotion emails). Routed through the .env MAIL_*
    |                        SMTP config so admins can verify the layout via
    |                        their own SMTP inbox. Defaults to the default
    |                        mailer (MAIL_MAILER).
    |
    |  - "newsletter_mailer" Real confirmation emails sent when a visitor
    |                        subscribes through a public site form. Routed
    |                        through the shared SendGrid relay (SENDGRID_API_KEY)
    |                        so production deliverability is handled by SendGrid.
    |
    */

    'test_mailer' => env('MAIL_TEST_MAILER', env('MAIL_MAILER', 'smtp')),

    'newsletter_mailer' => env('MAIL_NEWSLETTER_MAILER', 'sendgrid'),

    // Verified "From" address for ALL real (SendGrid) sends — public subscription
    // confirmations AND promotion blasts. Must live on the SendGrid-verified
    // domain (winpalack.com) so mail passes SPF/DKIM for every site. The per-site
    // from_name (the site's name) is kept; only the address is forced to this.
    'newsletter_from_address' => env('MAIL_NEWSLETTER_FROM_ADDRESS', 'info@winpalack.com'),

    // Double opt-in: slugs of sites that require the subscriber to click the
    // verify link before they count as verified. Every site sends the verify
    // email; sites NOT listed here auto-verify on subscribe (the link still
    // works and lands on the congrats page), while listed sites (e.g. winpalack)
    // stay unverified until the link is clicked. Comma-separated env override.
    'verify_required_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MAIL_VERIFY_REQUIRED_SLUGS', 'winpalack')),
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
