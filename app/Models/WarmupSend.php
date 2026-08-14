<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One warmup run — which site's template went out, to how many addresses.
 *
 * The per-send parameters live here rather than on {@see WarmupEmail} because
 * they describe the REQUEST, not the address: putting site/template/count on the
 * address table would rewrite every row on every run and lose the history of
 * what each address actually received.
 */
class WarmupSend extends Model
{
    protected $fillable = [
        'site_id',
        'user_id',
        'template',
        'requested_count',
        'queued_count',
    ];

    protected function casts(): array
    {
        return [
            'requested_count' => 'integer',
            'queued_count'    => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the admin asked for the whole list rather than a fixed number. */
    public function targetsEveryone(): bool
    {
        return $this->requested_count === null;
    }

    /** Human-readable summary for the admin toast and the log line. */
    public function summary(): string
    {
        $scope = $this->targetsEveryone()
            ? 'every address on the list'
            : "the {$this->requested_count} least recently contacted";

        // Resolved from the catalog so a renamed template label follows here too.
        $label = app(EmailTemplateCatalog::class)->label($this->template);

        return "Queued the {$label} template for {$this->site?->name} to {$scope} ({$this->queued_count} queued).";
    }
}
