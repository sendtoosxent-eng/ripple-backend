<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;

class Notifier
{
    public static function send(int $userId, string $type, array $data): void
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'data' => $data,
            'read' => false,
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }
}
