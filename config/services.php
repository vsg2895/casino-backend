<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'sendgrid' => [
        // API key for the native SendGrid Web API transport (config('mail.public_mailer')).
        // ONLY used for public verification emails when a visitor subscribes.
        // Replace the placeholder in .env with the real key on the live server.
        'key' => env('SENDGRID_API_KEY'),
        'from_domain' => env('SENDGRID_FROM_DOMAIN', 'example.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SendGrid Email Address Validation
    |--------------------------------------------------------------------------
    |
    | A SEPARATE key from the send key above. The validation endpoint requires
    | the "Email Address Validation" permission, which the transactional send key
    | does not carry — using it returns 403.
    |
    | Used in EXACTLY ONE place: the public newsletter subscribe endpoint. It is
    | deliberately not reachable from warmup, promotions, the post-verification
    | promotion, imports or admin-created subscribers; those must never spend a
    | validation credit.
    |
    | The plan allows 2,500 validations a month, so every value here exists to
    | protect that budget or to fail safely when it runs out.
    */
    'sendgrid_validation' => [
        // Never logged, never returned to a client. Placeholder until the real
        // key is pasted on the server; the code treats a fake/missing key as
        // "no result" and fails OPEN.
        'key' => env('SENDGRID_VALIDATION_KEY'),

        // Master switch. Off, the subscribe flow behaves exactly as it did
        // before this feature existed.
        'enabled' => (bool) env('SENDGRID_VALIDATION_ENABLED', true),

        /*
         * Verdicts that earn a verification email.
         *
         * Parsed to an array HERE so the verdict names never appear in the
         * controller or the service, and widening to "Valid,Risky" is an env
         * change rather than a deploy. SendGrid returns Valid | Risky | Invalid.
         */
        'allowed_verdicts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SENDGRID_VALIDATION_ALLOWED_VERDICTS', 'Valid')),
        ))),

        /*
         * Minimum score to ALLOW. SendGrid returns 0..1; below this an
         * otherwise-allowed verdict becomes a soft reject.
         *
         * Config, never a literal, because the right number is not knowable in
         * advance — it comes from reading a month of the score histogram in the
         * admin log and moving the threshold accordingly.
         */
        'min_score' => (float) env('SENDGRID_VALIDATION_MIN_SCORE', 0.7),

        /*
         * Per-check rejection switches.
         *
         * Known bounces and disposable domains reject by default; role
         * addresses (info@, support@, sales@) do NOT, because on a B2B-leaning
         * audience they are frequently the real person's real mailbox. All three
         * are env-flippable on the same evidence the score threshold is.
         */
        'reject_on_known_bounces' => filter_var(env('SENDGRID_VALIDATION_REJECT_ON_KNOWN_BOUNCES', true), FILTER_VALIDATE_BOOLEAN),
        'reject_on_disposable'    => filter_var(env('SENDGRID_VALIDATION_REJECT_ON_DISPOSABLE', true), FILTER_VALIDATE_BOOLEAN),
        'reject_on_role_address'  => filter_var(env('SENDGRID_VALIDATION_REJECT_ON_ROLE_ADDRESS', false), FILTER_VALIDATE_BOOLEAN),

        // Hard monthly ceiling counted locally, per calendar month in UTC. Only
        // REAL API calls count — cache hits and skips do not.
        'monthly_quota' => (int) env('SENDGRID_VALIDATION_MONTHLY_QUOTA', 2500),

        // Seconds. This call now sits in the request path in front of the
        // visitor, so it is deliberately short. No retry on timeout: a retry
        // doubles the wait AND may spend a second credit.
        'timeout' => (int) env('SENDGRID_VALIDATION_TIMEOUT', 3),

        // How long a verdict is reused for the same address. A repeat subscribe
        // must never spend a credit.
        'cache_days' => (int) env('SENDGRID_VALIDATION_CACHE_DAYS', 60),

        /*
         * Per-address cooldown, in seconds, between REAL API attempts.
         *
         * The verdict cache above already makes a repeat of a SUCCESSFUL check
         * free — but failures are deliberately not cached (caching an outage
         * would leave days of unvalidated subscribes). That leaves one hole: a
         * script looping a single address while SendGrid is failing would spend
         * a credit on every pass. This closes it.
         */
        'email_cooldown_seconds' => (int) env('SENDGRID_VALIDATION_EMAIL_COOLDOWN', 60),

        // Months of validation logs kept by `email-validation:prune`. These rows
        // hold visitor email addresses, so they are not kept forever.
        'log_retention_months' => (int) env('SENDGRID_VALIDATION_LOG_MONTHS', 12),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'revalidation' => [
        'secret' => env('REVALIDATE_SECRET'),
    ],

    'postback' => [
        'token' => env('CONVERSION_POSTBACK_TOKEN'),
    ],

];
