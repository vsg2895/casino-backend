<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One site's title/description pattern for one entity type.
 *
 * Per site, not global: the six domains share their casino records, so identical
 * patterns would put byte-identical titles on six URLs for the same casino —
 * exactly the duplicate content the per-site wording in each `copy.ts` exists to
 * avoid.
 */
class SeoTemplate extends Model
{
    public const string ENTITY_CASINO = 'casino';
    public const string ENTITY_SPECIAL_OFFER = 'special_offer';
    public const string ENTITY_CATEGORY = 'category';
    public const string ENTITY_PAGE = 'page';
    public const string ENTITY_LISTING = 'listing';

    /** @var list<string> */
    public const array ENTITIES = [
        self::ENTITY_CASINO,
        self::ENTITY_SPECIAL_OFFER,
        self::ENTITY_CATEGORY,
        self::ENTITY_PAGE,
        self::ENTITY_LISTING,
    ];

    protected $fillable = ['site_id', 'entity', 'title_pattern', 'description_pattern'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
