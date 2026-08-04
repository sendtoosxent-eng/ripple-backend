<?php

namespace Tests\Feature;

use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_preferences_are_persistent_and_validated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/notification-preferences')
            ->assertOk()
            ->assertJson(['push' => true, 'sound' => true, 'vibrate' => false]);

        $this->patchJson('/api/notification-preferences', ['sound' => false, 'reminders' => false])
            ->assertOk()
            ->assertJson(['sound' => false, 'reminders' => false]);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertFalse($user->fresh()->notification_preferences['sound']);
    }

    public function test_a_user_can_register_and_remove_a_push_subscription(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $endpoint = 'https://push.example.test/subscriptions/device-1';

        $this->postJson('/api/push/subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)],
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $user->id, 'endpoint' => $endpoint]);

        $this->deleteJson('/api/push/subscriptions', ['endpoint' => $endpoint])->assertNoContent();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $endpoint]);
    }

    public function test_post_activity_creates_notifications(): void
    {
        [$author, $friend] = User::factory()->count(2)->create();
        FriendRequest::create(['sender_id' => $author->id, 'receiver_id' => $friend->id, 'status' => 'accepted']);

        Sanctum::actingAs($author);
        $postId = $this->postJson('/api/posts', ['text' => 'A test post'])
            ->assertCreated()
            ->json('id');

        Sanctum::actingAs($friend);
        $this->postJson('/api/posts/'.$postId.'/like')->assertOk();
        $this->postJson('/api/posts/'.$postId.'/comments', ['text' => 'Nice'])->assertCreated();
        $this->postJson('/api/posts/'.$postId.'/repost')->assertOk();

        Sanctum::actingAs($author);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(3);
    }

    public function test_statuses_are_visible_to_friends_and_can_be_replied_to(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        FriendRequest::create(['sender_id' => $alice->id, 'receiver_id' => $bob->id, 'status' => 'accepted']);

        Sanctum::actingAs($alice);
        $statusId = $this->postJson('/api/statuses', [
            'type' => 'text',
            'text' => 'Available today',
            'background' => '#0f766e',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($bob);
        $this->getJson('/api/statuses')->assertOk()->assertJsonFragment(['id' => $statusId]);
        $this->postJson('/api/statuses/'.$statusId.'/view')->assertOk();
        $this->postJson('/api/statuses/'.$statusId.'/reply', ['text' => 'Great'])->assertCreated();
        $this->postJson('/api/statuses/'.$statusId.'/like')->assertOk()->assertJson(['liked' => true, 'likes_count' => 1]);
        $repostId = $this->postJson('/api/statuses/'.$statusId.'/repost')->assertCreated()->json('id');

        $this->assertDatabaseHas('status_views', ['status_id' => $statusId, 'viewer_id' => $bob->id]);
        $this->assertDatabaseHas('messages', ['status_reply_id' => $statusId, 'sender_id' => $bob->id]);
        $this->assertDatabaseHas('status_likes', ['status_id' => $statusId, 'user_id' => $bob->id]);
        $this->assertDatabaseHas('statuses', ['id' => $repostId, 'user_id' => $bob->id, 'reposted_from_id' => $statusId]);
    }
}
