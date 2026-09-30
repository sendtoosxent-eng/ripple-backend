<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;
use App\Models\MessageReceipt;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    // GET /api/conversations — chat list screen
    public function index(Request $request)
    {
        $conversations = $request->user()->conversations()
            ->with(['latestMessage.sender:id,name,username,avatar', 'members:id,name,username,avatar,online'])
            ->get()
            ->sortByDesc(fn ($conversation) => $conversation->latestMessage?->created_at ?? $conversation->created_at)
            ->values();

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

        $conversation->messages()
            ->where('sender_id', '!=', $request->user()->id)
            ->where('status', 'sent')
            ->update(['status' => 'delivered']);
        MessageReceipt::where('user_id', $request->user()->id)
            ->whereHas('message', fn ($query) => $query->where('conversation_id', $conversation->id)->where('sender_id', '!=', $request->user()->id))
            ->whereNull('delivered_at')->update(['delivered_at' => now()]);

        $conversation->load(['members:id,name,username,avatar,online,status']);
        $items = $conversation->messages()->reorder()->with([
            'sender:id,name,username,avatar', 'replyTo.sender:id,name,username', 'statusReply', 'reactions', 'receipts',
        ])->orderByDesc('id')->limit(41)->get();
        $conversation->setRelation('messages', $items->take(40)->reverse()->values());
        $conversation->has_more_messages = $items->count() > 40;

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
            'member_ids.*' => 'integer|distinct|exists:users,id',
            'is_group' => 'boolean',
            'name' => 'required_if:is_group,true|string|nullable|max:100',
            'avatar' => 'nullable|image|max:4096',
        ]);

        $memberIds = array_unique(array_merge($data['member_ids'], [$request->user()->id]));

        abort_if(! empty($data['is_group']) && count($memberIds) < 3, 422, 'Choose at least two friends for your group.');
        abort_if(empty($data['is_group']) && count($memberIds) !== 2, 422, 'Choose one friend for a direct conversation.');

        $nonFriendIds = array_filter(
            $data['member_ids'],
            fn ($id) => (int) $id !== $request->user()->id && ! $request->user()->isFriendsWith((int) $id),
        );

        if (! empty($nonFriendIds)) {
            return response()->json([
                'message' => 'You can only start a chat with friends. Add them as a friend first.',
            ], 403);
        }

        $blockedIds = array_filter(
            $data['member_ids'],
            fn ($id) => (int) $id !== $request->user()->id
                && ($request->user()->hasBlocked((int) $id) || $request->user()->isBlockedBy((int) $id)),
        );

        if (! empty($blockedIds)) {
            return response()->json(['message' => 'A conversation cannot include a blocked account.'], 403);
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
        abort_unless($conversation->is_group, 422, 'You cannot leave a one-to-one conversation.');

        DB::transaction(function () use ($request, $conversation) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $conversation->members()->detach($request->user()->id);
            if ((int) $conversation->created_by === $request->user()->id) {
                $conversation->update(['created_by' => $conversation->members()->orderBy('conversation_user.id')->value('users.id')]);
            }
        });

        return response()->json(['message' => 'Left conversation']);
    }
    // Metadata does not mark messages as read.
    public function details(Request $request, Conversation $conversation)
    {
        $member = $conversation->members()->where('users.id', $request->user()->id)->first();
        abort_unless($member, 403);
        $conversation->muted = (bool) $member->pivot->muted;
        return response()->json($conversation->load('members:id,name,username,avatar,online'));
    }

    public function addMembers(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'member_ids' => 'required|array|min:1|max:100',
            'member_ids.*' => 'required|integer|distinct|exists:users,id',
        ]);
        DB::transaction(function () use ($request, $conversation, $data) {
            $group = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($request, $group);
            foreach ($data['member_ids'] as $id) {
                if ($group->members()->where('users.id', $id)->exists()) continue;
                abort_unless($request->user()->isFriendsWith((int) $id), 403, 'Only your friends can be added.');
                foreach ($group->members as $member) {
                    abort_if($member->hasBlocked((int) $id) || $member->isBlockedBy((int) $id), 403, 'A group cannot include a blocked account.');
                }
            }
            $group->members()->syncWithoutDetaching($data['member_ids']);
        });
        return $this->details($request, $conversation);
    }

    public function removeMember(Request $request, Conversation $conversation, int $user)
    {
        DB::transaction(function () use ($request, $conversation, $user) {
            $group = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($request, $group);
            abort_if($user === $request->user()->id, 422, 'Use Leave group to leave and pass on admin access.');
            $group->members()->detach($user);
        });
        return $this->details($request, $conversation);
    }

    private function authorizeOwner(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->is_group, 422, 'This action is only available for groups.');
        abort_unless((int) $conversation->created_by === $request->user()->id
            && $conversation->members()->where('users.id', $request->user()->id)->exists(), 403, 'Only the group admin can manage members.');
    }

}
