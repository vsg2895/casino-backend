<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable link in a site's header or footer menu.
 *
 * Site-scoped by design: navigation is the clearest place the six domains should
 * differ, and a shared menu would undo that.
 */
class NavItem extends Model
{
    public const string LOCATION_HEADER = 'header';
    public const string LOCATION_FOOTER = 'footer';

    /** @var list<string> */
    public const array LOCATIONS = [self::LOCATION_HEADER, self::LOCATION_FOOTER];

    protected $fillable = [
        'site_id',
        'location',
        'label',
        'url',
        'position',
        'active',
        'opens_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'position'         => 'integer',
            'active'           => 'boolean',
            'opens_in_new_tab' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Published links for one site, in the order an editor set. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('position')->orderBy('id');
    }
}
