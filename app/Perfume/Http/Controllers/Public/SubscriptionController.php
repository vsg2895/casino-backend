<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\SubscribeRequest;
use App\Perfume\Jobs\SendPerfumeWelcomeEmail;
use App\Perfume\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The storefront's mailing list: joining it and leaving it.
 *
 * Subscribing twice is not an error — the same answer comes back either way.
 * Telling a stranger "that address is already on the list" would turn the
 * form into a way to test whether someone shops here.
 */
class SubscriptionController extends Controller
{
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim($request->string('email')->toString()));
        $name = $request->filled('name') ? trim($request->string('name')->toString()) : null;

        $subscriber = Subscriber::query()->where('email', $email)->first();

        if ($subscriber === null) {
            $subscriber = Subscriber::create([
                'email'  => $email,
                'name'   => $name,
                'active' => true,
                'token'  => Subscriber::generateToken(),
            ]);
            SendPerfumeWelcomeEmail::dispatch($subscriber->id);
        } elseif (! $subscriber->active) {
            // Coming back after opting out: greet them again.
            $subscriber->update(['active' => true, 'unsubscribed_at' => null, 'name' => $name ?? $subscriber->name]);
            SendPerfumeWelcomeEmail::dispatch($subscriber->id);
        } elseif ($name !== null && $subscriber->name === null) {
            $subscriber->update(['name' => $name]);
        }

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    /**
     * Opting out. POST only, and by token: a GET link is fetched by mail
     * clients scanning the message, which would unsubscribe real people
     * before they ever saw it.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');

        $subscriber = $token === '' ? null : Subscriber::query()->where('token', $token)->first();

        if ($subscriber !== null && $subscriber->active) {
            $subscriber->update(['active' => false, 'unsubscribed_at' => now()]);
        }

        // The same answer for a good and an unknown token: this endpoint must
        // not confirm whether a token exists.
        return response()->json(['data' => ['unsubscribed' => true]]);
    }
}
