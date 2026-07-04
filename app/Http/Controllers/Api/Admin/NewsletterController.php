<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkNewsletterIdsRequest;
use App\Http\Requests\Admin\ImportNewslettersRequest;
use App\Http\Requests\Admin\StoreNewsletterRequest;
use App\Http\Resources\NewsletterResource;
use App\Models\Newsletter;
use App\Services\NewsletterImportService;
use App\Support\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $siteId = $request->integer('site_id') ?: null;
        $trashed = $request->boolean('trashed');

        $query = Newsletter::with('site')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when($trashed, fn ($q) => $q->orderByDesc('deleted_at'), fn ($q) => $q->latest());

        return NewsletterResource::collection($query->paginate(50));
    }

    public function store(StoreNewsletterRequest $request): NewsletterResource
    {
        $newsletter = Newsletter::firstOrCreate([
            'site_id' => $request->integer('site_id'),
            'email'   => $request->validated('email'),
        ]);

        return new NewsletterResource($newsletter->load('site'));
    }

    /**
     * Bulk-import subscribers from an uploaded .xlsx / .csv with an "Email"
     * column. Existing (or previously-unsubscribed) addresses are kept/restored,
     * not duplicated. Imported contacts are added silently — no welcome email is
     * sent (this is a list import, not a public subscription).
     */
    public function import(ImportNewslettersRequest $request, NewsletterImportService $importer): JsonResponse
    {
        $siteId = $request->integer('site_id');
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        $emails = $importer->emails($file->getRealPath(), $extension);

        if ($emails === []) {
            return response()->json([
                'imported' => 0,
                'skipped'  => 0,
                'total'    => 0,
                'message'  => 'No valid email addresses found. Make sure the file has an "Email" column.',
            ], 422);
        }

        $imported = 0;
        $skipped = 0;

        foreach ($emails as $email) {
            // withTrashed so the (site_id, email) unique index — which still
            // covers soft-deleted rows — never trips on a re-import.
            $newsletter = Newsletter::withTrashed()->firstOrCreate([
                'site_id' => $siteId,
                'email'   => $email,
            ]);

            if ($newsletter->wasRecentlyCreated) {
                $imported++;
            } elseif ($newsletter->trashed()) {
                $newsletter->restore();
                $imported++;
            } else {
                $skipped++; // already an active subscriber
            }
        }

        return response()->json([
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => count($emails),
            'message'  => "Imported {$imported} subscriber(s)"
                . ($skipped > 0 ? ", skipped {$skipped} already on the list." : '.'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $siteId = $request->integer('site_id') ?: null;

        $rows = Newsletter::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->latest()
            ->cursor()
            ->map(fn (Newsletter $n) => [
                $n->email,
                $n->created_at?->format('d/m/Y, g:i A') ?? '',
            ]);

        return CsvExport::download('newsletter.csv', ['Email address', 'Created at'], $rows);
    }

    /** Soft-delete a single subscriber. */
    public function destroy(Newsletter $newsletter): JsonResponse
    {
        $newsletter->delete();

        return response()->json(null, 204);
    }

    /** Soft-delete the checkbox-selected subscribers. */
    public function bulkDestroy(BulkNewsletterIdsRequest $request): JsonResponse
    {
        $deleted = Newsletter::whereIn('id', $request->ids())->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /** Soft-delete every subscriber, optionally scoped to one site. */
    public function destroyAll(Request $request): JsonResponse
    {
        $siteId = $request->integer('site_id') ?: null;

        $deleted = Newsletter::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /** Restore a single soft-deleted subscriber. */
    public function restore(Newsletter $newsletter): NewsletterResource
    {
        $newsletter->restore();

        return new NewsletterResource($newsletter->load('site'));
    }

    /** Restore the checkbox-selected soft-deleted subscribers. */
    public function bulkRestore(BulkNewsletterIdsRequest $request): JsonResponse
    {
        $restored = Newsletter::onlyTrashed()->whereIn('id', $request->ids())->restore();

        return response()->json(['restored' => $restored]);
    }

    /** Permanently delete a single trashed subscriber. */
    public function forceDestroy(Newsletter $newsletter): JsonResponse
    {
        $newsletter->forceDelete();

        return response()->json(null, 204);
    }

    /** Permanently delete the checkbox-selected trashed subscribers. */
    public function bulkForceDestroy(BulkNewsletterIdsRequest $request): JsonResponse
    {
        $deleted = Newsletter::onlyTrashed()->whereIn('id', $request->ids())->forceDelete();

        return response()->json(['deleted' => $deleted]);
    }
}
