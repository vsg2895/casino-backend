<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentCoverageResource;
use App\Models\Casino;
use App\Models\CasinoSiteContent;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferSiteContent;
use App\Services\SiteContentEditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which casinos / offers carry their own content on which site.
 *
 * Three queries whatever the catalogue size: the records, the override pairs,
 * and the active attachments. Each cell is `custom` (this site has a row),
 * `default` (attached, uses the default) or `not_attached` (the site does not
 * publish it, so there is nothing to write yet).
 */
class ContentCoverageController extends Controller
{
    public function __construct(private readonly SiteContentEditor $editor) {}

    public function index(Request $request): ContentCoverageResource
    {
        // Read-only GET filter, validated inline (see CLAUDE.md).
        $type = $request->validate(['type' => ['required', 'in:casino,offer']])['type'];

        $sites = $this->editor->editableSites();

        // casino:site pairs the site publishes; an offer follows its casino.
        $attached = DB::table('casino_site')->where('active', true)->get(['casino_id', 'site_id'])
            ->mapWithKeys(static fn (object $row): array => [$row->casino_id . ':' . $row->site_id => true]);

        if ($type === 'casino') {
            $records = Casino::query()->orderBy('name')->get(['id', 'name', 'slug']);
            $custom = CasinoSiteContent::query()->get(['casino_id as record_id', 'site_id']);
            $casinoOf = static fn (Casino $record): int => (int) $record->id;
        } else {
            $records = SpecialOffer::query()->with('casino:id,name')->orderBy('title')->get(['id', 'title', 'slug', 'casino_id']);
            $custom = SpecialOfferSiteContent::query()->get(['special_offer_id as record_id', 'site_id']);
            $casinoOf = static fn (SpecialOffer $record): int => (int) $record->casino_id;
        }

        $customPairs = $custom->mapWithKeys(static fn ($row): array => [$row->record_id . ':' . $row->site_id => true]);

        $rows = $records->map(static function ($record) use ($sites, $customPairs, $attached, $casinoOf, $type): array {
            $cells = [];

            foreach ($sites as $site) {
                // Keyed by SLUG: an all-integer key set would be renumbered
                // into a list when the resource serialises it.
                $cells[$site->slug] = match (true) {
                    isset($customPairs[$record->id . ':' . $site->id])        => 'custom',
                    isset($attached[$casinoOf($record) . ':' . $site->id])    => 'default',
                    default                                                   => 'not_attached',
                };
            }

            return [
                'id'          => $record->id,
                'name'        => $type === 'casino' ? $record->name : $record->title,
                'slug'        => $record->slug,
                'casino_name' => $type === 'offer' ? $record->casino?->name : null,
                'cells'       => $cells,
            ];
        })->values()->all();

        return new ContentCoverageResource([
            'type'  => $type,
            'sites' => $sites->map(static fn (Site $site): array => [
                'id' => $site->id, 'name' => $site->name, 'slug' => $site->slug,
            ])->values()->all(),
            'rows'  => $rows,
        ]);
    }
}
