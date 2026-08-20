<?php

declare(strict_types=1);

use App\Models\EmailSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repoint the post-verification promotion from a stored SendGrid key to the
 * .env SENDGRID_API_KEY.
 *
 * The feature shipped defaulting to provider 'sendgrid', which required picking
 * an admin-managed key. That option is retired here: SendGrid now means the
 * environment key, so the row is moved to 'sendgrid_env' and its stale
 * sendgrid_key_id cleared.
 *
 * SCOPE IS ONE TABLE, deliberately. `email_schedules` also stores
 * provider='sendgrid' and is NOT touched — scheduled campaigns keep selecting
 * their own stored key, and rewriting them here would silently repoint every
 * existing campaign at a different credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('verification_promotion_emails')
            ->where('provider', EmailSchedule::PROVIDER_SENDGRID)
            ->update([
                'provider'        => EmailSchedule::PROVIDER_SENDGRID_ENV,
                'sendgrid_key_id' => null,
            ]);
    }

    public function down(): void
    {
        // Back to the stored-key provider. The key id cannot be restored (it was
        // dropped on the way up), so a rollback leaves the row needing a key
        // selected again — which is exactly the state it was in before.
        DB::table('verification_promotion_emails')
            ->where('provider', EmailSchedule::PROVIDER_SENDGRID_ENV)
            ->update(['provider' => EmailSchedule::PROVIDER_SENDGRID]);
    }
};
