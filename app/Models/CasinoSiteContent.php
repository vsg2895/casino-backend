<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsSiteContent;
use Database\Factories\CasinoSiteContentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One site's own description / meta / noindex for a shared casino.
 *
 * Read only through {@see \App\Services\SiteContentResolver}.
 */
class CasinoSiteContent extends Model
{
    /** @use HasFactory<CasinoSiteContentFactory> */
    use HasFactory, IsSiteContent;

    protected $fillable = ['casino_id'];

    public function casino(): BelongsTo
    {
        return $this->belongsTo(Casino::class);
    }
}
