<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $device = UserDevice::updateOrCreate(
            [
                'push_token' => $validated['push_token'],
            ],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'enabled' => true,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Device registered successfully.',
            'device' => $device,
        ]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string'],
        ]);

        UserDevice::where('user_id', $request->user()->id)
            ->where('push_token', $validated['push_token'])
            ->update([
                'enabled' => false,
                'last_seen_at' => now(),
            ]);

        return response()->json([
            'message' => 'Device unregistered successfully.',
        ]);
    }
}