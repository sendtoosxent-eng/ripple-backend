<?php

namespace Tests\Feature;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;
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
        ])->assertCreated()->json('message.id');
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

    public function test_one_to_one_voice_call_log_is_stored_as_a_message(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $conversation = $this->conversation([$alice, $bob], $alice);

        Sanctum::actingAs($alice);
        $response = $this->postJson('/api/conversations/'.$conversation->id.'/messages', [
            'type' => 'call',
            'call_status' => 'completed',
            'call_duration' => 73,
        ])->assertCreated();

        $this->assertDatabaseHas('messages', [
            'id' => $response->json('message.id'),
            'conversation_id' => $conversation->id,
            'sender_id' => $alice->id,
            'type' => 'call',
            'call_status' => 'completed',
            'call_duration' => 73,
        ]);
    }

    public function test_incoming_call_creates_a_notification_for_the_other_member(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $conversation = $this->conversation([$alice, $bob], $alice);

        Sanctum::actingAs($alice);
        $this->postJson('/api/conversations/'.$conversation->id.'/calls/notify')->assertNoContent();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $bob->id,
            'type' => 'incoming_call',
        ]);
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

    public function test_message_creation_is_idempotent_for_a_sender_client_uuid(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $conversation = $this->conversation([$alice, $bob], $alice);
        Sanctum::actingAs($alice);
        $clientId = (string) Str::uuid();

        $first = $this->postJson('/api/conversations/'.$conversation->id.'/messages', [
            'client_message_id' => $clientId, 'type' => 'text', 'text' => 'Send once',
        ])->assertCreated()->assertJson(['created' => true]);
        $second = $this->postJson('/api/conversations/'.$conversation->id.'/messages', [
            'client_message_id' => $clientId, 'type' => 'text', 'text' => 'Send once',
        ])->assertOk()->assertJson(['created' => false]);

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_different_senders_may_use_the_same_client_uuid_but_a_sender_cannot_reuse_it_in_another_conversation(): void
    {
        [$alice, $bob, $carol] = User::factory()->count(3)->create();
        $first = $this->conversation([$alice, $bob], $alice);
        $second = $this->conversation([$alice, $carol], $alice);
        $clientId = (string) Str::uuid();

        Sanctum::actingAs($alice);
        $this->postJson('/api/conversations/'.$first->id.'/messages', ['client_message_id' => $clientId, 'type' => 'text', 'text' => 'Alice'])->assertCreated();
        $this->postJson('/api/conversations/'.$second->id.'/messages', ['client_message_id' => $clientId, 'type' => 'text', 'text' => 'Wrong chat'])->assertConflict();

        Sanctum::actingAs($bob);
        $this->postJson('/api/conversations/'.$first->id.'/messages', ['client_message_id' => $clientId, 'type' => 'text', 'text' => 'Bob'])->assertCreated();
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_conversation_history_is_paginated_newest_first_without_losing_order(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $conversation = $this->conversation([$alice, $bob], $alice);
        foreach (range(1, 45) as $number) {
            Message::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'type' => 'text', 'text' => "Message {$number}", 'status' => 'sent']);
        }

        Sanctum::actingAs($bob);
        $initial = $this->getJson('/api/conversations/'.$conversation->id)->assertOk();
        $this->assertCount(40, $initial->json('messages'));
        $this->assertTrue($initial->json('has_more_messages'));
        $oldestLoadedId = $initial->json('messages.0.id');

        $older = $this->getJson('/api/conversations/'.$conversation->id.'/messages?before_id='.$oldestLoadedId)
            ->assertOk()
            ->assertJson(['has_more' => false]);
        $this->assertCount(5, $older->json('data'));
        $this->assertSame('Message 1', $older->json('data.0.text'));
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
