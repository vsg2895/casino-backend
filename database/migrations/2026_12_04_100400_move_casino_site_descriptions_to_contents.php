<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the per-site casino texts written so far into `casino_site_contents`.
 *
 * `casino_site.description` (2026_11_26) already held a site's OWN text — it
 * was never a copy of the global one — so moving it is not the "copy defaults
 * into overrides" this feature forbids. The old column is left in place and
 * simply stops being read, so `down()` only has to remove what `up()` created.
 *
 * Idempotent: a pair that already has a content row keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('casino_site', 'description') || ! Schema::hasTable('casino_site_contents')) {
            return;
        }

        $now = now();

        DB::table('casino_site')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('casino_id')
            ->orderBy('site_id')
            ->get(['casino_id', 'site_id', 'description'])
            ->each(static function (object $row) use ($now): void {
                if (trim(html_entity_decode(strip_tags((string) $row->description))) === '') {
                    return;
                }

                DB::table('casino_site_contents')->insertOrIgnore([
                    'casino_id'   => $row->casino_id,
                    'site_id'     => $row->site_id,
                    'description' => $row->description,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('casino_site', 'description') || ! Schema::hasTable('casino_site_contents')) {
            return;
        }

        // Only rows that are exactly a moved text and nothing more. Ids first,
        // then a plain delete: an aliased DELETE is not portable across drivers.
        $ids = DB::table('casino_site_contents as c')
            ->whereNull('c.meta_title')
            ->whereNull('c.meta_description')
            ->whereNull('c.noindex')
            ->whereExists(static function ($q): void {
                $q->from('casino_site as p')
                    ->whereColumn('p.casino_id', 'c.casino_id')
                    ->whereColumn('p.site_id', 'c.site_id')
                    ->whereColumn('p.description', 'c.description');
            })
            ->pluck('c.id');

        DB::table('casino_site_contents')->whereIn('id', $ids)->delete();
    }
};
