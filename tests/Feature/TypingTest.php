<?php
namespace Tests\Feature;

use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TypingTest extends TestCase
{
    use RefreshDatabase;
    public function test_typing_is_member_only_excludes_self_stops_and_expires(): void
    {
        Event::fake([UserTyping::class]);
        [$alice, $bob, $carol, $outsider] = User::factory()->count(4)->create();
        foreach ([false, true] as $group) {
            $chat = Conversation::create(['is_group' => $group, 'created_by' => $alice->id]);
            $chat->members()->attach($group ? [$alice->id, $bob->id, $carol->id] : [$alice->id, $bob->id]);
            $url = '/api/conversations/'.$chat->id.'/typing';
            Sanctum::actingAs($outsider);
            $this->getJson($url)->assertForbidden();
            $this->postJson($url, ['is_typing' => true])->assertForbidden();
            Sanctum::actingAs($alice);
            $this->postJson($url, ['is_typing' => true, 'user_id' => $outsider->id, 'name' => 'Fake'])->assertNoContent();
            $this->getJson($url)->assertJsonCount(0, 'typing');
            Sanctum::actingAs($bob);
            $this->getJson($url)->assertJsonPath('typing.0.user_id', $alice->id)->assertJsonPath('typing.0.name', $alice->name);
            if ($group) {
                Sanctum::actingAs($carol);
                $this->postJson($url, ['is_typing' => true])->assertNoContent();
                Sanctum::actingAs($bob);
                $this->getJson($url)->assertJsonCount(2, 'typing');
            }
            Sanctum::actingAs($alice);
            $this->postJson($url, ['is_typing' => false])->assertNoContent();
            Sanctum::actingAs($bob);
            $this->getJson($url)->assertJsonCount($group ? 1 : 0, 'typing');
            $this->travel(7)->seconds();
            $this->getJson($url)->assertJsonCount(0, 'typing');
            Sanctum::actingAs($alice);
            $this->postJson($url, ['is_typing' => true])->assertNoContent();
            $chat->members()->detach($alice->id);
            $this->postJson($url, ['is_typing' => true])->assertForbidden();
            Sanctum::actingAs($bob);
            $this->getJson($url)->assertJsonCount(0, 'typing');
        }
        Event::assertDispatched(UserTyping::class, fn ($event) => $event->userId === $alice->id && $event->name === $alice->name && $event->isTyping);
    }
}
