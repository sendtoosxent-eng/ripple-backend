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
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->members->contains($request->user()->id), 403);
        $data = $request->validate(['before_id' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:10|max:100']);
        $limit = $data['limit'] ?? 40;
        $query = $conversation->messages()->reorder()->with([
            'sender:id,name,username,avatar',
            'replyTo.sender:id,name,username',
            'statusReply',
            'reactions',
            'receipts',
        ]);
        if (! empty($data['before_id'])) $query->where('id', '<', $data['before_id']);
        $items = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $messages = $items->take($limit)->reverse()->values();

        return response()->json(['data' => $messages, 'has_more' => $hasMore]);
    }

    // POST /api/conversations/{conversation}/messages
    public function store(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->members->contains($request->user()->id), 403);

        if (! $conversation->is_group) {
            $other = $conversation->members->firstWhere('id', '!=', $request->user()->id);
            if ($other && ($request->user()->hasBlocked($other->id) || $request->user()->isBlockedBy($other->id))) {
                abort(403, 'You cannot message this user.');
                // message('You cannot message this user because you have blocked them or they have blocked you.');
            }
        }

        $data = $request->validate([
            'client_message_id' => 'nullable|uuid',
            'type' => 'required|in:text,image,voice,call',
            'text' => 'required_if:type,text|nullable|string',
            'caption' => 'nullable|string', // optional caption when type=image
            'image' => 'required_if:type,image|nullable|image|max:8192',
            'audio' => 'required_if:type,voice|nullable|file|mimes:webm,mp3,m4a,wav,ogg|max:8192',
            'duration' => 'required_if:type,voice|nullable|string', // e.g. "0:14"
            'call_status' => 'required_if:type,call|nullable|in:missed,declined,completed',
            'call_duration' => 'nullable|integer|min:0|max:86400',
            'reply_to_id' => 'nullable|exists:messages,id',
        ]);

        $existing = ! empty($data['client_message_id'])
            ? Message::where('sender_id', $request->user()->id)->where('client_message_id', $data['client_message_id'])->first()
            : null;
        if ($existing) {
            abort_unless($existing->conversation_id === $conversation->id, 409, 'This message identifier is already used in another conversation.');
            $existing->load(['sender:id,name,username,avatar', 'replyTo.sender:id,name,username', 'reactions']);
            return response()->json(['message' => $existing, 'created' => false]);
        }

        if (! empty($data['reply_to_id'])) {
            $replyBelongsToConversation = $conversation->messages()
                ->whereKey($data['reply_to_id'])
                ->exists();

            abort_unless($replyBelongsToConversation, 422, 'The replied-to message is not part of this conversation.');
        }

        $payload = [
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'client_message_id' => $data['client_message_id'] ?? null,
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

        if ($data['type'] === 'call') {
            abort_unless(! $conversation->is_group, 422, 'Group call logs are not supported.');
            $payload['call_status'] = $data['call_status'];
            $payload['call_duration'] = $data['call_duration'] ?? 0;
        }

        try {
            $message = DB::transaction(function () use ($conversation, $payload, $request) {
                $message = $conversation->messages()->create($payload);
                $message->receipts()->createMany($conversation->members()->where('users.id', '!=', $request->user()->id)->get()->map(fn ($recipient) => ['user_id' => $recipient->id])->all());
                return $message;
            });
        } catch (QueryException $exception) {
            if (empty($data['client_message_id'])) throw $exception;
            $message = Message::where('sender_id', $request->user()->id)
                ->where('client_message_id', $data['client_message_id'])
                ->first();
            if (! $message) throw $exception;
            abort_unless($message->conversation_id === $conversation->id, 409, 'This message identifier is already used in another conversation.');
            $message->load(['sender:id,name,username,avatar', 'replyTo.sender:id,name,username', 'reactions']);
            return response()->json(['message' => $message, 'created' => false]);
        }
        $message->load(['sender:id,name,username,avatar', 'replyTo.sender:id,name,username', 'reactions', 'receipts']);

        foreach ($conversation->members()->where('users.id', '!=', $request->user()->id)->get() as $recipient) {
            if (! $recipient->pivot->muted) {
                Notifier::send($recipient->id, 'new_message', [
                    'actor_id' => $request->user()->id,
                    'actor_name' => $request->user()->name,
                    'actor_avatar' => $request->user()->avatar_url,
                    'conversation_id' => $conversation->id,
                    'preview' => $message->type === 'text' ? \Illuminate\Support\Str::limit($message->text, 80) : ucfirst($message->type).' message',
                ]);
            }
        }

        \App\Services\SafeBroadcast::send(new MessageSent($message));

        return response()->json(['message' => $message, 'created' => true], 201);
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
        \App\Services\SafeBroadcast::send(new MessageReacted($message));

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
        \App\Models\MessageReceipt::where('user_id', $request->user()->id)
            ->whereHas('message', fn ($query) => $query->where('conversation_id', $conversation->id)->where('sender_id', '!=', $request->user()->id))
            ->whereNull('read_at')->update(['delivered_at' => now(), 'read_at' => now()]);

        $conversation->members()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
        ]);

        \App\Services\SafeBroadcast::send(new MessagesRead($conversation->id, $request->user()->id));

        return response()->json(['message' => 'Marked as read']);
    }
}
