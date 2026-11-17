<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\HomeCardRequest;
use App\Perfume\Http\Resources\HomeCardResource;
use App\Perfume\Models\HomeCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HomeCardController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return HomeCardResource::collection(HomeCard::query()->ordered()->get());
    }

    public function store(HomeCardRequest $request): JsonResponse
    {
        $row = HomeCard::create($request->validated());

        return (new HomeCardResource($row))->response()->setStatusCode(201);
    }

    public function show(HomeCard $homeCard): HomeCardResource
    {
        return new HomeCardResource($homeCard);
    }

    public function update(HomeCardRequest $request, HomeCard $homeCard): HomeCardResource
    {
        $homeCard->update($request->validated());

        return new HomeCardResource($homeCard->fresh());
    }

    public function destroy(HomeCard $homeCard): JsonResponse
    {
        $homeCard->delete();

        return response()->json(null, 204);
    }
}
