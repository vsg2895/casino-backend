<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The forum settings as the ADMIN edits them.
 *
 * Raw columns, nulls included — the editor must see an empty box where they
 * cleared one, not the default silently filled in. `resolved` rides alongside so
 * the screen can show what the public page will actually render, which is the
 * only way an editor can tell "I left this blank" apart from "this is what
 * visitors see".
 */
class SiteForumResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'site_id'          => $this->site_id,
            'enabled'          => (bool) $this->enabled,
            'title'            => $this->title,
            'eyebrow'          => $this->eyebrow,
            'show_eyebrow'     => (bool) $this->show_eyebrow,
            'intro'            => $this->intro,
            'meta_title'       => $this->meta_title,
            'meta_description' => $this->meta_description,
            'noindex'          => (bool) $this->noindex,
            'empty_title'      => $this->empty_title,
            'empty_body'       => $this->empty_body,
            'empty_cta_label'  => $this->empty_cta_label,
            'empty_cta_url'    => $this->empty_cta_url,
            'show_stats'       => (bool) $this->show_stats,
            'editorial_enabled' => (bool) $this->editorial_enabled,
            'editorial_title'  => $this->editorial_title,
            'editorial_body'   => $this->editorial_body,
            'threads_per_page' => (int) $this->threads_per_page,
            'preview_reviews'  => (int) $this->preview_reviews,
            'resolved'         => $this->resolved(),
        ];
    }
}
