<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One URL redirect for one site.
 *
 * Paths are normalised on write so matching is a plain string comparison in the
 * front end. Without that, "/old", "/old/" and "/OLD" would be three different
 * rules for one URL and only one of them would ever fire.
 */
class Redirect extends Model
{
    /** @var list<int> */
    public const array STATUS_CODES = [301, 302];

    protected $fillable = [
        'site_id',
        'source_path',
        'destination_path',
        'status_code',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'active'      => 'boolean',
            'hits'        => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Canonical form of a path: leading slash, no trailing slash, lower-cased.
     *
     * Applied to BOTH the stored source and the incoming pathname, so the two
     * are compared in the same shape. The root path is the one exception — "/"
     * must not be stripped to an empty string.
     *
     * Destinations are normalised too, EXCEPT that an absolute URL is left
     * untouched: redirecting to another domain is legitimate and must not have
     * its scheme mangled.
     */
    public static function normalisePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $path = mb_strtolower($path);
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
