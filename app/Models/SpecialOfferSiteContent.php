<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsSiteContent;
use Database\Factories\SpecialOfferSiteContentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One site's own description / meta / noindex for a shared special offer.
 *
 * Read only through {@see \App\Services\SiteContentResolver}.
 */
class SpecialOfferSiteContent extends Model
{
    /** @use HasFactory<SpecialOfferSiteContentFactory> */
    use HasFactory, IsSiteContent;

    protected $fillable = ['special_offer_id'];

    public function specialOffer(): BelongsTo
    {
        return $this->belongsTo(SpecialOffer::class);
    }
}
