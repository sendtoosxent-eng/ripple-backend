<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function key()
    {
        $key = config('services.webpush.public_key');
        abort_unless($key, 503, 'Push notifications are not configured yet.');

        return response()->json(['public_key' => $key]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|url|max:2048',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'content_encoding' => 'nullable|string|max:30',
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $request->user()->id,
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent' => $request->userAgent(),
            ],
        );

        return response()->json($subscription, 201);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|url|max:2048']);
        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->noContent();
    }
}
