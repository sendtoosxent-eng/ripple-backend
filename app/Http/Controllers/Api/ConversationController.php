<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    // GET /api/conversations — chat list screen
    public function index(Request $request)
    {
        $conversations = $request->user()->conversations()
            ->with(['latestMessage.sender:id,name,username,avatar', 'members:id,name,username,avatar,online'])
            ->get();

        // Unread count = messages from other people since this user last read the conversation
        $conversations->each(function ($conversation) use ($request) {
            $lastRead = $conversation->pivot->last_read_at;
            $conversation->unread = $conversation->messages()
                ->where('sender_id', '!=', $request->user()->id)
                ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
                ->count();
        });

        return response()->json($conversations);
    }

    // GET /api/conversations/{conversation} — full chat room with messages
    public function show(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->members->contains($request->user()->id), 403);

        $conversation->load([
            'messages.sender:id,name,username,avatar',
            'messages.replyTo.sender:id,name,username',
            'messages.reactions',
            'members:id,name,username,avatar,online,status',
        ]);

        // mark as read
        $conversation->members()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
        ]);

        return response()->json($conversation);
    }

    // POST /api/conversations — start a 1-to-1 or group chat
    public function store(Request $request)
    {
        $data = $request->validate([
            'member_ids' => 'required|array|min:1',
            'member_ids.*' => 'exists:users,id',
            'is_group' => 'boolean',
            'name' => 'required_if:is_group,true|string|nullable',
            'avatar' => 'nullable|image|max:4096',
        ]);

        $memberIds = array_unique(array_merge($data['member_ids'], [$request->user()->id]));

        $nonFriendIds = array_filter(
            $data['member_ids'],
            fn ($id) => (int) $id !== $request->user()->id && ! $request->user()->isFriendsWith((int) $id),
        );

        if (! empty($nonFriendIds)) {
            return response()->json([
                'message' => 'You can only start a chat with friends. Add them as a friend first.',
            ], 403);
        }

        // For 1-1 chats, reuse an existing conversation between these two people instead of
        // creating a duplicate every time someone taps "Message".
        if (empty($data['is_group']) && count($data['member_ids']) === 1) {
            $otherId = $data['member_ids'][0];

            $existing = $request->user()->conversations()
                ->where('is_group', false)
                ->whereHas('members', fn ($q) => $q->where('users.id', $otherId))
                ->first();

            if ($existing) {
                return response()->json($existing->load('members'));
            }
        }

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = CloudinaryUploader::upload($request->file('avatar'), 'conversation-avatars');
        }

        $conversation = Conversation::create([
            'is_group' => $data['is_group'] ?? false,
            'name' => $data['name'] ?? null,
            'avatar' => $avatarPath,
            'created_by' => $request->user()->id,
        ]);

        $conversation->members()->attach($memberIds);

        return response()->json($conversation->load('members'), 201);
    }

    // PATCH /api/conversations/{conversation}/mute — toggle mute for me only
    public function toggleMute(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->members->contains($request->user()->id), 403);

        $current = $conversation->members()->where('user_id', $request->user()->id)->first()->pivot->muted;

        $conversation->members()->updateExistingPivot($request->user()->id, [
            'muted' => ! $current,
        ]);

        return response()->json(['muted' => ! $current]);
    }

    // POST /api/conversations/{conversation}/leave
    public function leave(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->members->contains($request->user()->id), 403);

        $conversation->members()->detach($request->user()->id);

        return response()->json(['message' => 'Left conversation']);
    }
}
