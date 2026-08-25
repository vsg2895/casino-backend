<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarmupSendRecipient */
class WarmupSendRecipientResource extends JsonResource
{
    /**
     * Template labels, resolved once per request rather than once per row.
     *
     * {@see EmailTemplateCatalog} is a plain (non-singleton) binding with three
     * injected services, so an `app()` call inside toArray() would rebuild it for
     * every row of a 200-row page.
     *
     * @var array<string, string>|null
     */
    private static ?array $labels = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'email' => $this->email,

            // Null when the address has since been removed from the warmup list.
            // `email` above is the denormalised copy that keeps the row readable.
            'warmup_email_id' => $this->warmup_email_id,
            'warmup_send_id'  => $this->warmup_send_id,

            'template' => $this->template,
            // Resolved from the catalog, so a renamed template follows here too.
            // Falls back to the raw key for a template since removed from it.
            'template_label' => self::labels()[$this->template] ?? $this->template,

            'status' => $this->status,
            'error'  => $this->error,

            // Only present when eager-loaded. The site may also have been deleted
            // since the send, which nulls the FK by design — the history outlives it.
            'site' => $this->whenLoaded(
                'site',
                fn (): ?array => $this->site === null ? null : [
                    'id'     => $this->site->id,
                    'name'   => $this->site->name,
                    'domain' => $this->site->domain,
                ],
            ),
            'site_id' => $this->site_id,

            // When the attempt actually happened. `created_at` is when the row was
            // flushed, which lags by up to one buffer.
            'sent_at'    => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        if (self::$labels === null) {
            $catalog = app(EmailTemplateCatalog::class);

            self::$labels = collect($catalog->types())
                ->pluck('label', 'value')
                ->all();
        }

        return self::$labels;
    }
}
