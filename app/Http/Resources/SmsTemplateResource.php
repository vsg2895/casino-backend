<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SmsTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SmsTemplate */
class SmsTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'name'    => $this->name,
            'body'    => $this->body,
            'preview' => $this->preview(),
            'status'  => $this->status,

            // Cost per recipient, computed server-side so the listing does not
            // have to re-derive it. The editor recomputes live as you type, from
            // the same rule (admin/src/utils/smsSegments.ts).
            // UTF-16 code units, matching both the carrier's count and the
            // editor's live figure. See SmsTemplate::billedLength().
            'length'        => $this->billedLength(),
            'segments'      => $this->segments(),
            'uses_unicode'  => $this->usesUnicode(),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
