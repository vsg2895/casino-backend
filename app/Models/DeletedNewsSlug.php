<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SchemaGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The tombstone of a hard-deleted news post — see the 2026_12_02 migration.
 *
 * @property string $slug
 * @property string|null $source_ref
 * @property bool $was_public
 * @property Carbon $deleted_at
 */
class DeletedNewsSlug extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'slug', 'source_ref', 'was_public', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'was_public' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /** Leave a tombstone for a news post about to be force-deleted. */
    public static function record(Article $post): void
    {
        if (! self::ready()) {
            return;
        }

        self::query()->upsert([[
            'site_id'    => $post->site_id,
            'slug'       => (string) $post->slug,
            'source_ref' => $post->source_ref,
            // Ever public: a publish date and a body. A draft never had a URL
            // anyone could have indexed, so it never answers 410.
            'was_public' => $post->published_at !== null && trim((string) $post->body) !== '',
            'deleted_at' => $post->deleted_at ?? now(),
        ]], ['site_id', 'slug'], ['source_ref', 'was_public', 'deleted_at']);
    }

    /** True when a deleted post of this site held this slug or feed id. */
    public static function holds(int $siteId, ?string $slug = null, ?string $sourceRef = null): bool
    {
        if (! self::ready() || ($slug === null && $sourceRef === null)) {
            return false;
        }

        return self::query()
            ->where('site_id', $siteId)
            ->where(static function (Builder $q) use ($slug, $sourceRef): void {
                if ($slug !== null) {
                    $q->orWhere('slug', $slug);
                }

                if ($sourceRef !== null) {
                    $q->orWhere('source_ref', $sourceRef);
                }
            })
            ->exists();
    }

    /** Skipped until the migration has run, like every other new column here. */
    public static function ready(): bool
    {
        return SchemaGuard::hasTable('deleted_news_slugs');
    }
}
