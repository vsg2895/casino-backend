<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which casino-listing filters one site offers, and in what order.
 *
 * The facet KEYS are code — each needs a query behind it — while the choice of
 * which to offer, their labels and their order are editorial. That split is why
 * this table holds no query logic.
 */
class FacetConfig extends Model
{
    public const string FACET_CATEGORY = 'category';
    public const string FACET_COUNTRY = 'country';
    public const string FACET_LICENCE = 'licence';
    public const string FACET_PAYMENT_METHOD = 'payment_method';
    public const string FACET_PROVIDER = 'provider';

    /** @var list<string> */
    public const array FACETS = [
        self::FACET_CATEGORY,
        self::FACET_COUNTRY,
        self::FACET_LICENCE,
        self::FACET_PAYMENT_METHOD,
        self::FACET_PROVIDER,
    ];

    /**
     * Wording used when a site has not overridden it.
     *
     * @var array<string, string>
     */
    public const array DEFAULT_LABELS = [
        self::FACET_CATEGORY       => 'Category',
        self::FACET_COUNTRY        => 'Country',
        self::FACET_LICENCE        => 'Licence',
        self::FACET_PAYMENT_METHOD => 'Payment method',
        self::FACET_PROVIDER       => 'Game provider',
    ];

    protected $fillable = ['site_id', 'facet', 'label', 'position', 'active'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'active' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
