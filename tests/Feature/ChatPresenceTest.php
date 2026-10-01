<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_members_include_last_seen_after_presence_changes(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $chat = Conversation::create(['is_group' => false, 'created_by' => $alice->id]);
        $chat->members()->attach([$alice->id, $bob->id]);
        Sanctum::actingAs($bob);
        $this->postJson('/api/presence', ['online' => true])->assertSuccessful();
        $this->assertTrue($bob->fresh()->online);
        $this->postJson('/api/presence', ['online' => false])->assertSuccessful();
        $seen = $bob->fresh()->last_seen_at->toISOString();
        Sanctum::actingAs($alice);
        foreach (['/api/conversations/'.$chat->id, '/api/conversations/'.$chat->id.'/details', '/api/conversations'] as $url) {
            $data = $this->getJson($url)->assertOk()->json();
            $members = $url === '/api/conversations' ? $data[0]['members'] : $data['members'];
            $member = collect($members)->firstWhere('id', $bob->id);
            $this->assertFalse($member['online']);
            $this->assertSame($seen, $member['last_seen_at']);
        }
    }
}
