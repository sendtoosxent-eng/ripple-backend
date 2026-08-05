<?php

namespace App\Services;

use App\Models\PushSubscription as StoredSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushSender
{
    public static function send(int $userId, string $type, array $data): void
    {
        $publicKey = config('services.webpush.public_key');
        $privateKey = config('services.webpush.private_key');
        if (! $publicKey || ! $privateKey) return;

        $payload = self::payload($type, $data);

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject', 'mailto:support@example.com'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]]);

            $stored = StoredSubscription::where('user_id', $userId)->get();
            foreach ($stored as $record) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $record->endpoint,
                    'publicKey' => $record->p256dh,
                    'authToken' => $record->auth,
                    'contentEncoding' => $record->content_encoding,
                ]), json_encode($payload));
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    StoredSubscription::where('endpoint', $report->getRequest()->getUri()->__toString())->delete();
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Web push delivery failed', ['message' => $exception->getMessage(), 'type' => $type]);
        }
    }

    private static function payload(string $type, array $data): array
    {
        $actor = $data['actor_name'] ?? 'Someone';
        return match ($type) {
            'new_message' => ['title' => $actor, 'body' => $data['preview'] ?? 'Sent you a message', 'url' => '/chats/'.($data['conversation_id'] ?? '')],
            'incoming_call' => ['title' => 'Incoming voice call', 'body' => "$actor is calling you", 'url' => '/chats/'.($data['conversation_id'] ?? ''), 'tag' => 'incoming-call-'.($data['conversation_id'] ?? ''), 'require_interaction' => true, 'incoming_call' => true],
            'friend_accepted' => ['title' => 'Friend request accepted', 'body' => "$actor accepted your friend request", 'url' => '/users/'.($data['actor_id'] ?? '')],
            'post_liked' => ['title' => 'New like', 'body' => "$actor liked your post", 'url' => '/posts'],
            'post_commented' => ['title' => 'New comment', 'body' => "$actor commented on your post", 'url' => '/posts'],
            'post_reposted' => ['title' => 'New repost', 'body' => "$actor reposted your post", 'url' => '/posts'],
            'status_liked' => ['title' => 'Status liked', 'body' => "$actor liked your update", 'url' => '/status'],
            'status_reposted' => ['title' => 'Status reshared', 'body' => "$actor reshared your update", 'url' => '/status'],
            default => ['title' => 'Ripple', 'body' => "$actor shared an update", 'url' => '/notifications'],
        };
    }
}
