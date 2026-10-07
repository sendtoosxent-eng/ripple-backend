<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;

class Notifier
{
    public static function send(int $userId, string $type, array $data): void
    {
        $user = User::find($userId);

        if (! $user) {
            return;
        }

        $preferences = array_merge([
            'messages' => true,
            'social' => true,
        ], $user->notification_preferences ?? []);

        if (
            $type === 'new_message' &&
            ! $preferences['messages']
        ) {
            return;
        }

        if (
            ! in_array($type, ['new_message', 'incoming_call'], true) &&
            ! $preferences['social']
        ) {
            return;
        }

        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'data' => $data,
            'read' => false,
        ]);

        // Realtime Ripple notification
        SafeBroadcast::send(
            new NotificationCreated($notification)
        );

        // Existing browser push notifications
        WebPushSender::send(
            $userId,
            $type,
            $data
        );

        // iPhone / Android mobile push notifications
        ExpoPushSender::send(
            $userId,
            $type,
            $data
        );
    }
}