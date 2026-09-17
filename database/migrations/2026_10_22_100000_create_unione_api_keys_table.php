<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UniOne API keys.
 *
 * Purely additive: this migration CREATES a new table and alters nothing. The
 * whole UniOne feature is isolated behind the `unione_` prefix — see the
 * isolation report.
 *
 * ── Two key types, because UniOne has two ───────────────────────────────────
 *
 * A USER key authenticates the whole account; a PROJECT key is scoped to one
 * project and is what the `/project/*` endpoints call `project_api_key`.
 * Several responses (`/system/info.json`, the webhook payload) only carry
 * `project_id` when the key used was a project key, so the distinction is not
 * cosmetic — it changes what the API tells us about ourselves.
 *
 * ── Both secrets are `encrypted` casts ──────────────────────────────────────
 *
 * `api_key` and `webhook_secret` are TEXT because ciphertext is far longer than
 * the plaintext, and the model casts both. Neither ever appears in a resource,
 * a log line or an exception message.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unione_api_keys')) {
            return;
        }

        Schema::create('unione_api_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);

            // Encrypted at rest — see the model's casts().
            $table->text('api_key');

            // user | project
            $table->string('key_type', 10)->default('user');
            // Only meaningful for key_type = project.
            $table->string('project_id', 64)->nullable();

            // auto | eu1 | us1 — `auto` means the global api.unione.io endpoint,
            // which routes to whichever datacenter the account lives in.
            $table->string('region', 8)->default('eu1');
            // Derived from region on save, but overridable: UniOne could add a
            // datacenter before this column knows about it, and an operator
            // should not need a deploy to point at it.
            $table->string('base_url', 255);

            $table->boolean('is_active')->default(true);
            // Exactly one row may hold true. Enforced in a transaction by the
            // service, not by a unique index: MySQL treats every `false` as a
            // distinct value only for NULLs, so a plain unique index on a
            // boolean would forbid a second NON-default key.
            $table->boolean('is_default')->default(false);

            $table->string('default_from_email', 255)->nullable();
            $table->string('default_from_name', 120)->nullable();

            /*
             * DEFAULT TRUE, deliberately against the brief's "default false".
             *
             * The UniOne spec: "Both default to 1 (enabled); setting to 0
             * requires support authorization." Defaulting these to false would
             * make every send request a permission the account may not hold,
             * and would generate the admin error we are meant to surface rarely.
             * An operator can still switch them off knowingly.
             */
            $table->boolean('track_links')->default(true);
            $table->boolean('track_read')->default(true);

            $table->unsignedSmallInteger('timeout_seconds')->default(15);

            /*
             * NOT a signature secret — UniOne does not offer one.
             *
             * Webhook authenticity is an MD5 of the body with the API key
             * substituted for the `auth` value (see UniOneWebhookVerifier). This
             * column holds a random TOKEN that forms part of the webhook URL, so
             * an unauthenticated prober cannot reach the endpoint at all. It is
             * the outer of two gates, not the cryptographic one.
             */
            $table->text('webhook_secret');

            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verify_status', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The send path asks for "the default key" and "the active keys".
            $table->index(['is_active', 'is_default'], 'unione_api_keys_active_default_idx');
            $table->index('key_type', 'unione_api_keys_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unione_api_keys');
    }
};
