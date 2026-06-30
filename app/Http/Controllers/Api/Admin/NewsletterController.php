<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkNewsletterIdsRequest;
use App\Http\Requests\Admin\StoreNewsletterRequest;
use App\Http\Resources\NewsletterResource;
use App\Models\Newsletter;
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
