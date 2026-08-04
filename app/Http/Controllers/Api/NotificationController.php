<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private const DEFAULT_PREFERENCES = [
        'push' => true,
        'sound' => true,
        'vibrate' => false,
        'messages' => true,
        'social' => true,
        'reminders' => true,
    ];

    // GET /api/notifications — my recent notifications, newest first.
    // Viewing the list marks everything as read (simple, no per-item read tracking needed).
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        Notification::where('user_id', $request->user()->id)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json($notifications);
    }

    // GET /api/notifications/unread-count — for the nav badge, without marking as read
    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function preferences(Request $request)
    {
        return response()->json(array_merge(
            self::DEFAULT_PREFERENCES,
            $request->user()->notification_preferences ?? [],
        ));
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'push' => 'sometimes|boolean',
            'sound' => 'sometimes|boolean',
            'vibrate' => 'sometimes|boolean',
            'messages' => 'sometimes|boolean',
            'social' => 'sometimes|boolean',
            'reminders' => 'sometimes|boolean',
        ]);

        $preferences = array_merge(
            self::DEFAULT_PREFERENCES,
            $request->user()->notification_preferences ?? [],
            $data,
        );

        $request->user()->update(['notification_preferences' => $preferences]);

        return response()->json($preferences);
    }
}
