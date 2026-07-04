<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
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
        ]);

        $memberIds = array_unique(array_merge($data['member_ids'], [$request->user()->id]));

        $conversation = Conversation::create([
            'is_group' => $data['is_group'] ?? false,
            'name' => $data['name'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $conversation->members()->attach($memberIds);

        return response()->json($conversation->load('members'), 201);
    }
}
