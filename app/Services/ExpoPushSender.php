<?php

namespace App\Services;

use App\Models\UserDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpoPushSender
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    public static function send(int $userId, string $type, array $data): void
    {
        $devices = UserDevice::query()
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        $content = self::payload($type, $data);

        foreach ($devices as $device) {
            try {
                $message = [
                    'to' => $device->push_token,
                    'sound' => 'default',
                    'title' => $content['title'],
                    'body' => $content['body'],
                    'data' => $content['data'],
                ];

                if ($device->platform === 'android') {
                    $message['channelId'] = 'messages';
                }

                $request = Http::acceptJson()
                    ->asJson();

                $accessToken = config('services.expo.access_token');

                if ($accessToken) {
                    $request = $request->withToken($accessToken);
                }

                $response = $request->post(
                    self::EXPO_PUSH_URL,
                    $message
                );

                if (! $response->successful()) {
                    Log::warning('Expo push request failed', [
                        'user_id' => $userId,
                        'device_id' => $device->id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                $ticket = $response->json('data');

                if (
                    is_array($ticket) &&
                    ($ticket['status'] ?? null) === 'error'
                ) {
                    $error = $ticket['details']['error'] ?? null;

                    Log::warning('Expo rejected push notification', [
                        'user_id' => $userId,
                        'device_id' => $device->id,
                        'error' => $error,
                        'message' => $ticket['message'] ?? null,
                    ]);

                    if ($error === 'DeviceNotRegistered') {
                        $device->update([
                            'enabled' => false,
                        ]);
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Expo push delivery failed', [
                    'user_id' => $userId,
                    'device_id' => $device->id,
                    'type' => $type,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private static function payload(string $type, array $data): array
    {
        $actor = $data['actor_name'] ?? 'Someone';

        return match ($type) {
            'new_message' => [
                'title' => $actor,
                'body' => $data['preview'] ?? 'Sent you a message',
                'data' => [
                    'type' => 'message',
                    'conversationId' => (string) ($data['conversation_id'] ?? ''),
                    'senderId' => (string) ($data['actor_id'] ?? ''),
                    'actorName' => $actor,
                ],
            ],

            'incoming_call' => [
                'title' => 'Incoming voice call',
                'body' => "{$actor} is calling you",
                'data' => [
                    'type' => 'incoming_call',
                    'conversationId' => (string) ($data['conversation_id'] ?? ''),
                    'senderId' => (string) ($data['actor_id'] ?? ''),
                ],
            ],

            'friend_accepted' => [
                'title' => 'Friend request accepted',
                'body' => "{$actor} accepted your friend request",
                'data' => [
                    'type' => 'friend_accepted',
                    'userId' => (string) ($data['actor_id'] ?? ''),
                ],
            ],

            'friend_request' => [
                'title' => 'New friend request',
                'body' => "{$actor} sent you a friend request",
                'data' => [
                    'type' => 'friend_request',
                    'userId' => (string) ($data['actor_id'] ?? ''),
                    'actorName' => $actor,
                ],
            ],

            'new_post' => [
                'title' => $actor,
                'body' => $data['preview'] ?? 'Shared a new post',
                'data' => [
                    'type' => 'new_post',
                    'postId' => (string) ($data['post_id'] ?? ''),
                    'actorName' => $actor,
                ],
            ],

            'post_liked' => [
                'title' => 'New like',
                'body' => "{$actor} liked your post",
                'data' => [
                    'type' => 'post_liked',
                    'postId' => (string) ($data['post_id'] ?? ''),
                    'actorName' => $actor,
                ],
            ],

            'post_commented' => [
                'title' => 'New comment',
                'body' => "{$actor} commented on your post",
                'data' => [
                    'type' => 'post_commented',
                    'postId' => (string) ($data['post_id'] ?? ''),
                    'actorName' => $actor,
                ],
            ],

            'post_reposted' => [
                'title' => 'New repost',
                'body' => "{$actor} reposted your post",
                'data' => [
                    'type' => 'post_reposted',
                    'postId' => (string) ($data['post_id'] ?? ''),
                    'actorName' => $actor,
                ],
            ],

            'status_liked' => [
                'title' => 'Status liked',
                'body' => "{$actor} liked your update",
                'data' => [
                    'type' => 'status_liked',
                ],
            ],

            'status_reposted' => [
                'title' => 'Status reshared',
                'body' => "{$actor} reshared your update",
                'data' => [
                    'type' => 'status_reposted',
                ],
            ],

            default => [
                'title' => 'Ripple',
                'body' => "{$actor} shared an update",
                'data' => [
                    'type' => $type,
                ],
            ],
        };
    }
}
