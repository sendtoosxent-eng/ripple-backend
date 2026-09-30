<?php

namespace Tests\Feature;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private function friend(User $owner, User $friend): void
    {
        FriendRequest::create(['sender_id' => $owner->id, 'receiver_id' => $friend->id, 'status' => 'accepted']);
    }

    public function test_group_creation_membership_messages_mute_and_exit(): void
    {
        [$owner, $bob, $carol, $dana] = User::factory()->count(4)->create();
        foreach ([$bob, $carol, $dana] as $friend) $this->friend($owner, $friend);
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/conversations', ['is_group' => true, 'name' => 'Our people', 'member_ids' => [$bob->id, $carol->id]])
            ->assertCreated()->assertJsonCount(3, 'members')->json('id');
        $base = '/api/conversations/'.$id;
        $this->postJson($base.'/members', ['member_ids' => [$dana->id]])->assertOk()->assertJsonCount(4, 'members');
        $this->postJson($base.'/members', ['member_ids' => [$dana->id]])->assertOk()->assertJsonCount(4, 'members');
        Sanctum::actingAs($dana);
        $this->getJson($base.'/details')->assertOk()->assertJsonPath('muted', false);
        $this->patchJson($base.'/mute')->assertOk()->assertJsonPath('muted', true);
        $this->getJson($base.'/details')->assertJsonPath('muted', true);
        $this->postJson($base.'/messages', ['type' => 'text', 'text' => 'Hello everyone'])->assertCreated();
        Sanctum::actingAs($bob);
        $this->getJson($base)->assertOk()->assertJsonPath('messages.0.text', 'Hello everyone');
        $this->getJson($base.'/details')->assertJsonPath('muted', false);
        Sanctum::actingAs($dana);
        $this->postJson($base.'/leave')->assertOk();
        $this->getJson($base)->assertForbidden();
        $this->getJson($base.'/details')->assertForbidden();
        $this->postJson($base.'/messages', ['type' => 'text', 'text' => 'No access'])->assertForbidden();
        $this->getJson('/api/conversations')->assertJsonCount(0);
    }

    public function test_only_admin_can_manage_members_and_removed_users_lose_access(): void
    {
        [$owner, $bob, $carol, $outsider] = User::factory()->count(4)->create();
        $group = Conversation::create(['name' => 'Friends', 'is_group' => true, 'created_by' => $owner->id]);
        $group->members()->attach([$owner->id, $bob->id, $carol->id]);
        $base = '/api/conversations/'.$group->id;
        foreach ([$bob, $outsider] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson($base.'/members', ['member_ids' => [$outsider->id]])->assertForbidden();
            $this->deleteJson($base.'/members/'.$carol->id)->assertForbidden();
        }
        Sanctum::actingAs($owner);
        $this->postJson($base.'/members', ['member_ids' => [$outsider->id]])->assertForbidden();
        $this->friend($owner, $outsider);
        BlockedUser::create(['blocker_id' => $carol->id, 'blocked_id' => $outsider->id]);
        $this->postJson($base.'/members', ['member_ids' => [$outsider->id]])->assertForbidden();
        $this->deleteJson($base.'/members/'.$owner->id)->assertUnprocessable();
        $this->deleteJson($base.'/members/'.$carol->id)->assertOk()->assertJsonCount(2, 'members');
        Sanctum::actingAs($carol);
        $this->getJson($base)->assertForbidden();
        $this->postJson($base.'/messages', ['type' => 'text', 'text' => 'No access'])->assertForbidden();
    }

    public function test_admin_exit_transfers_ownership_and_last_member_can_leave(): void
    {
        [$owner, $bob] = User::factory()->count(2)->create();
        $group = Conversation::create(['name' => 'Friends', 'is_group' => true, 'created_by' => $owner->id]);
        $group->members()->attach([$owner->id, $bob->id]);
        $base = '/api/conversations/'.$group->id;
        Sanctum::actingAs($owner);
        $this->postJson($base.'/leave')->assertOk();
        $this->assertDatabaseHas('conversations', ['id' => $group->id, 'created_by' => $bob->id]);
        $this->postJson($base.'/members', ['member_ids' => [$owner->id]])->assertForbidden();
        Sanctum::actingAs($bob);
        $this->postJson($base.'/leave')->assertOk();
        $this->assertDatabaseHas('conversations', ['id' => $group->id, 'created_by' => null]);
    }

    public function test_group_creation_requires_name_and_two_distinct_friends(): void
    {
        [$owner, $bob] = User::factory()->count(2)->create();
        $this->friend($owner, $bob);
        Sanctum::actingAs($owner);
        $this->postJson('/api/conversations', ['is_group' => true, 'name' => 'Friends', 'member_ids' => [$bob->id]])->assertUnprocessable();
        $this->postJson('/api/conversations', ['is_group' => true, 'member_ids' => [$bob->id, $bob->id]])->assertUnprocessable();
        $id = $this->postJson('/api/conversations', ['member_ids' => [$bob->id]])->assertCreated()->json('id');
        $this->postJson('/api/conversations/'.$id.'/members', ['member_ids' => [$bob->id]])->assertUnprocessable();
        $this->deleteJson('/api/conversations/'.$id.'/members/'.$bob->id)->assertUnprocessable();
    }
}
