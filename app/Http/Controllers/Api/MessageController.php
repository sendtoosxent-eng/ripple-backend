<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
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
        ]);

        $payload = [
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'type' => $data['type'],
            'status' => 'sent',
        ];

        if ($data['type'] === 'text') {
            $payload['text'] = $data['text'];
        }

        if ($data['type'] === 'image') {
            $path = $request->file('image')->store('chat-images', 'public');
            [$width, $height] = getimagesize($request->file('image')->getRealPath());
            $payload['media_path'] = $path;
            $payload['text'] = $data['caption'] ?? null;
            $payload['width'] = $width;
            $payload['height'] = $height;
        }

        if ($data['type'] === 'voice') {
            $path = $request->file('audio')->store('chat-voice', 'public');
            $payload['media_path'] = $path;
            $payload['voice_duration'] = $data['duration'];
            // waveform is generated client-side while recording and sent as JSON, see below
            $payload['waveform'] = $request->input('waveform')
                ? json_decode($request->input('waveform'), true)
                : null;
        }

        $message = $conversation->messages()->create($payload);
        $message->load('sender:id,name,username,avatar');

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message, 201);
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
