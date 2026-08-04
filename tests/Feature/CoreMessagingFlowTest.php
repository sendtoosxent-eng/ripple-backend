<?php

namespace Tests\Feature;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoreMessagingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_users_can_become_friends_chat_reply_react_and_read(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();

        Sanctum::actingAs($alice);
        $request = $this->postJson('/api/friend-requests', ['receiver_id' => $bob->id])
            ->assertCreated();

        Sanctum::actingAs($bob);
        $this->postJson('/api/friend-requests/'.$request->json('id').'/accept')
            ->assertOk();

        Sanctum::actingAs($alice);
        $conversationId = $this->postJson('/api/conversations', ['member_ids' => [$bob->id]])
            ->assertCreated()
            ->json('id');

        $firstMessageId = $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'type' => 'text',
            'text' => 'Hello Bob',
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $bob->id,
            'type' => 'new_message',
        ]);

        Sanctum::actingAs($bob);
        $this->getJson('/api/conversations/'.$conversationId)->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $firstMessageId, 'status' => 'delivered']);

        $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'type' => 'text',
            'text' => 'Hello Alice',
            'reply_to_id' => $firstMessageId,
        ])->assertCreated();

        $this->postJson('/api/messages/'.$firstMessageId.'/react', ['emoji' => '👍'])
            ->assertOk();
        $this->postJson('/api/conversations/'.$conversationId.'/read')->assertOk();

        $this->assertDatabaseHas('messages', ['id' => $firstMessageId, 'status' => 'read']);
        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $firstMessageId,
            'user_id' => $bob->id,
            'emoji' => '👍',
        ]);
    }

    public function test_reply_must_belong_to_the_same_conversation(): void
    {
        [$alice, $bob, $carol] = User::factory()->count(3)->create();
        $first = $this->conversation([$alice, $bob], $alice);
        $second = $this->conversation([$alice, $carol], $alice);
        $foreignMessage = Message::create([
            'conversation_id' => $second->id,
            'sender_id' => $alice->id,
            'type' => 'text',
            'text' => 'Other chat',
            'status' => 'sent',
        ]);

        Sanctum::actingAs($alice);
        $this->postJson('/api/conversations/'.$first->id.'/messages', [
            'type' => 'text',
            'text' => 'Invalid reply',
            'reply_to_id' => $foreignMessage->id,
        ])->assertStatus(422);
    }

    public function test_blocking_prevents_friend_requests_conversations_and_messages(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        BlockedUser::create(['blocker_id' => $alice->id, 'blocked_id' => $bob->id]);

        Sanctum::actingAs($bob);
        $this->postJson('/api/friend-requests', ['receiver_id' => $alice->id])->assertForbidden();

        FriendRequest::create(['sender_id' => $alice->id, 'receiver_id' => $bob->id, 'status' => 'accepted']);
        $this->postJson('/api/conversations', ['member_ids' => [$alice->id]])->assertForbidden();

        $conversation = $this->conversation([$alice, $bob], $alice);
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', [
            'type' => 'text',
            'text' => 'Blocked message',
        ])->assertForbidden();
    }

    public function test_one_to_one_conversation_cannot_be_left(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $conversation = $this->conversation([$alice, $bob], $alice);

        Sanctum::actingAs($alice);
        $this->postJson('/api/conversations/'.$conversation->id.'/leave')
            ->assertStatus(422);
    }

    private function conversation(array $members, User $creator): Conversation
    {
        $conversation = Conversation::create([
            'is_group' => false,
            'created_by' => $creator->id,
        ]);
        $conversation->members()->attach(collect($members)->pluck('id'));

        return $conversation;
    }
}
