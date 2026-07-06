<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageReacted;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    // POST /api/conversations/{conversation}/messages
    public function store(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->members->contains($request->user()->id), 403);

        $data = $request->validate([
            'type' => 'required|in:text,image,voice',
            'text' => 'required_if:type,text|nullable|string',
            'caption' => 'nullable|string', // optional caption when type=image
            'image' => 'required_if:type,image|nullable|image|max:8192',
            'audio' => 'required_if:type,voice|nullable|file|mimes:webm,mp3,m4a,wav,ogg|max:8192',
            'duration' => 'required_if:type,voice|nullable|string', // e.g. "0:14"
            'reply_to_id' => 'nullable|exists:messages,id',
        ]);

        $payload = [
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'type' => $data['type'],
            'status' => 'sent',
            'reply_to_id' => $data['reply_to_id'] ?? null,
        ];

        if ($data['type'] === 'text') {
            $payload['text'] = $data['text'];
        }

        if ($data['type'] === 'image') {
            $uploadedUrl = CloudinaryUploader::upload($request->file('image'), 'chat-images');
            [$width, $height] = getimagesize($request->file('image')->getRealPath());
            $payload['media_path'] = $uploadedUrl;
            $payload['text'] = $data['caption'] ?? null;
            $payload['width'] = $width;
            $payload['height'] = $height;
        }

        if ($data['type'] === 'voice') {
            $uploadedUrl = CloudinaryUploader::upload($request->file('audio'), 'chat-voice');
            $payload['media_path'] = $uploadedUrl;
            $payload['voice_duration'] = $data['duration'];
            $payload['waveform'] = $request->input('waveform')
                ? json_decode($request->input('waveform'), true)
                : null;
        }

        $message = $conversation->messages()->create($payload);
        $message->load(['sender:id,name,username,avatar', 'replyTo.sender:id,name,username', 'reactions']);

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message, 201);
    }

    // POST /api/messages/{message}/react — toggle/replace my reaction on a message
    public function react(Request $request, Message $message)
    {
        $conversation = $message->conversation;
        abort_unless($conversation->members->contains($request->user()->id), 403);

        $data = $request->validate(['emoji' => 'required|string|max:10']);

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing && $existing->emoji === $data['emoji']) {
            // tapping the same emoji again removes it
            $existing->delete();
        } else {
            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $request->user()->id],
                ['emoji' => $data['emoji']],
            );
        }

        $message->load('reactions');
        broadcast(new MessageReacted($message))->toOthers();

        return response()->json(['reaction_summary' => $message->reaction_summary]);
    }

    // POST /api/conversations/{conversation}/read
    // Marks every message NOT sent by me as read, and tells the sender(s) live via broadcast.
    public function markRead(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->members->contains($request->user()->id), 403);

        $conversation->messages()
            ->where('sender_id', '!=', $request->user()->id)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        $conversation->members()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
        ]);

        broadcast(new MessagesRead($conversation->id, $request->user()->id))->toOthers();

        return response()->json(['message' => 'Marked as read']);
    }
}
