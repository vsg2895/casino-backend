<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\SubscriberRequest;
use App\Perfume\Http\Resources\SubscriberResource;
use App\Perfume\Models\Subscriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));

        $subscribers = Subscriber::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->when($request->filled('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->orderByDesc('id')
            ->paginate(min(200, max(1, (int) $request->query('per_page', 50))));

        return SubscriberResource::collection($subscribers);
    }

    public function store(SubscriberRequest $request): JsonResponse
    {
        $subscriber = Subscriber::create([
            ...$request->validated(),
            'email' => mb_strtolower(trim($request->string('email')->toString())),
            'token' => Subscriber::generateToken(),
        ]);

        return (new SubscriberResource($subscriber))->response()->setStatusCode(201);
    }

    public function update(SubscriberRequest $request, Subscriber $subscriber): SubscriberResource
    {
        $data = $request->validated();

        // Switching someone off by hand is an opt-out like any other, so it
        // is stamped the same way the public endpoint stamps it.
        if (array_key_exists('active', $data)) {
            $data['unsubscribed_at'] = $data['active'] ? null : ($subscriber->unsubscribed_at ?? now());
        }

        $subscriber->update($data);

        return new SubscriberResource($subscriber->fresh());
    }

    public function destroy(Subscriber $subscriber): JsonResponse
    {
        $subscriber->delete();

        return response()->json(null, 204);
    }
}
