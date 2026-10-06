<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on `newsletters.email`, for the admin list's email search.
 *
 * `newsletters` is one of the two unbounded tables in this schema, so a filter
 * on it gets an index before it ships rather than after someone notices the
 * admin hanging.
 *
 * The existing `unique(site_id, email)` already serves the common search: the
 * screen always has a site selected, so `site_id = ? AND email LIKE 'x%'` is a
 * range scan on that key. It cannot serve the same search with NO site chosen —
 * the leading column is missing — and that request is reachable from the API.
 * This index covers it.
 *
 * Neither index helps a CONTAINS search (`LIKE '%x%'`), which no index can
 * serve. That is why the controller only runs one when the term starts with
 * `@` — a deliberate, explicit "find everyone at this domain" — and uses a
 * prefix match for everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('newsletters')) {
            return;
        }

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->index('email', 'newsletters_email_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('newsletters')) {
            return;
        }

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropIndex('newsletters_email_idx');
        });
    }
};
