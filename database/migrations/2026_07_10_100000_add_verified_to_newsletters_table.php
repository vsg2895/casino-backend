<?php

declare(strict_types=1);

use App\Models\Newsletter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double opt-in support: whether a subscriber confirmed their email via the
 * verify link. Sites that require verification (e.g. winpalack) leave this false
 * until the link is clicked; other sites auto-verify on subscribe. The verify
 * link reuses the existing per-subscriber unsubscribe_token as its credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->boolean('verified')->default(false)->after('email');
        });

        // Existing subscribers predate double opt-in — treat them as verified.
        Newsletter::withTrashed()->update(['verified' => true]);
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn('verified');
        });
    }
};
