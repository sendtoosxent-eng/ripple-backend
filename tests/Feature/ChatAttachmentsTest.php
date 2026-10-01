<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_and_group_members_can_send_photos_voice_notes_and_files(): void
    {
        config(['services.cloudinary.cloud_name' => 'test', 'services.cloudinary.upload_preset' => 'test']);
        Http::fake(['api.cloudinary.com/*' => Http::response(['secure_url' => 'https://example.com/media'])]);
        [$alice, $bob, $carol] = User::factory()->count(3)->create();
        foreach ([false, true] as $isGroup) {
            $chat = Conversation::create(['is_group' => $isGroup, 'created_by' => $alice->id]);
            $chat->members()->attach($isGroup ? [$alice->id, $bob->id, $carol->id] : [$alice->id, $bob->id]);
            Sanctum::actingAs($alice);
            $base = '/api/conversations/'.$chat->id;
            // A real tiny PNG avoids a dependency on GD in the test runtime.
            $image = UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP9sAAAAASUVORK5CYII='));
            $photo = $this->postJson($base.'/messages', ['type' => 'image', 'image' => $image, 'caption' => 'Hello 📸'])
                ->assertCreated()->assertJsonPath('message.text', 'Hello 📸')->assertJsonPath('message.sender.name', $alice->name)->json('message.id');
            $this->postJson($base.'/messages', ['type' => 'voice', 'audio' => UploadedFile::fake()->create('note.m4a', 20, 'audio/x-m4a'), 'duration' => '0:12', 'reply_to_id' => $photo])
                ->assertCreated()->assertJsonPath('message.voice_duration', '0:12')->assertJsonPath('message.reply_preview.preview', 'Photo');
            $uuid = (string) Str::uuid();
            $data = ['type' => 'file', 'file' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'), 'client_message_id' => $uuid];
            $file = $this->postJson($base.'/messages', $data)->assertCreated()
                ->assertJsonPath('message.file_name', 'notes.pdf')->assertJsonPath('message.file_size', 20480)->json('message.id');
            $this->postJson($base.'/messages', $data)->assertOk()->assertJsonPath('message.id', $file)->assertJsonPath('created', false);
            Sanctum::actingAs($bob);
            $this->getJson($base)->assertOk()->assertJsonCount(3, 'messages');
            $this->postJson('/api/messages/'.$file.'/react', ['emoji' => '👍'])->assertOk();
            $this->postJson($base.'/messages', ['type' => 'text', 'text' => 'Thanks', 'reply_to_id' => $file])->assertCreated()->assertJsonPath('message.reply_preview.preview', 'notes.pdf');
        }
        Http::assertSent(fn ($request) => str_contains($request->url(), '/raw/upload'));
        Http::assertSentCount(6);
    }

    public function test_invalid_or_unauthorized_attachments_do_not_upload(): void
    {
        Http::fake();
        [$alice, $bob, $outsider] = User::factory()->count(3)->create();
        $chat = Conversation::create(['is_group' => true, 'created_by' => $alice->id]);
        $chat->members()->attach([$alice->id, $bob->id]);
        $base = '/api/conversations/'.$chat->id.'/messages';
        Sanctum::actingAs($outsider);
        $this->postJson($base, ['type' => 'file', 'file' => UploadedFile::fake()->create('notes.pdf', 10)])->assertForbidden();
        Sanctum::actingAs($alice);
        $this->postJson($base, ['type' => 'file'])->assertUnprocessable();
        $this->postJson($base, ['type' => 'file', 'file' => UploadedFile::fake()->create('large.pdf', 20481)])->assertUnprocessable();
        $this->postJson($base, ['type' => 'voice', 'audio' => UploadedFile::fake()->create('note.m4a', 20, 'audio/x-m4a')])->assertUnprocessable();
        $this->postJson($base, ['type' => 'image', 'image' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])->assertUnprocessable();
        Http::assertNothingSent();
    }
}
