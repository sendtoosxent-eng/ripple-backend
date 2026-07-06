<?php

namespace App\Http\Controllers\Api;

use App\Events\FriendRequestUpdated;
use App\Http\Controllers\Controller;
use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Http\Request;

class FriendController extends Controller
{
    // POST /api/friend-requests — send a request
    public function store(Request $request)
    {
        $data = $request->validate(['receiver_id' => 'required|exists:users,id']);

        if ((int) $data['receiver_id'] === $request->user()->id) {
            return response()->json(['message' => 'You cannot friend yourself.'], 422);
        }

        // If the other person already sent me one, accept it instead of creating a duplicate
        $incoming = FriendRequest::where('sender_id', $data['receiver_id'])
            ->where('receiver_id', $request->user()->id)
            ->first();

        if ($incoming) {
            $incoming->update(['status' => 'accepted']);
            broadcast(new FriendRequestUpdated($incoming->fresh(), $request->user()->id))->toOthers();
            return response()->json($incoming->fresh());
        }

        $fr = FriendRequest::firstOrCreate(
            ['sender_id' => $request->user()->id, 'receiver_id' => $data['receiver_id']],
            ['status' => 'pending'],
        );

        broadcast(new FriendRequestUpdated($fr, $request->user()->id))->toOthers();

        return response()->json($fr, 201);
    }

    // GET /api/friend-requests — pending requests sent TO me
    public function index(Request $request)
    {
        $requests = FriendRequest::where('receiver_id', $request->user()->id)
            ->where('status', 'pending')
            ->with('sender:id,name,username,avatar')
            ->latest()
            ->get();

        return response()->json($requests);
    }

    // POST /api/friend-requests/{friendRequest}/accept
    public function accept(Request $request, FriendRequest $friendRequest)
    {
        abort_unless($friendRequest->receiver_id === $request->user()->id, 403);
        $friendRequest->update(['status' => 'accepted']);
        broadcast(new FriendRequestUpdated($friendRequest, $request->user()->id))->toOthers();

        return response()->json($friendRequest);
    }

    // POST /api/friend-requests/{friendRequest}/reject
    public function reject(Request $request, FriendRequest $friendRequest)
    {
        abort_unless($friendRequest->receiver_id === $request->user()->id, 403);
        $friendRequest->update(['status' => 'rejected']);

        return response()->json($friendRequest);
    }

    // GET /api/friends — my accepted friends list
    public function friends(Request $request)
    {
        $me = $request->user()->id;

        $accepted = \App\Models\FriendRequest::where('status', 'accepted')
            ->where(function ($q) use ($me) {
                $q->where('sender_id', $me)->orWhere('receiver_id', $me);
            })
            ->get();

        $friendIds = $accepted->map(fn ($fr) => $fr->sender_id === $me ? $fr->receiver_id : $fr->sender_id);

        $friends = User::whereIn('id', $friendIds)->select('id', 'name', 'username', 'avatar', 'online')->get();

        return response()->json($friends);
    }

    // GET /api/friend-status/{user} — relationship status with a specific user (for their profile screen)
    public function statusWith(Request $request, User $user)
    {
        $me = $request->user()->id;

        $fr = FriendRequest::where(function ($q) use ($me, $user) {
            $q->where('sender_id', $me)->where('receiver_id', $user->id);
        })->orWhere(function ($q) use ($me, $user) {
            $q->where('sender_id', $user->id)->where('receiver_id', $me);
        })->first();

        return response()->json([
            'status' => $fr?->status ?? 'none', // none | pending | accepted | rejected
            'request_id' => $fr?->id,
            'i_am_sender' => $fr?->sender_id === $me,
            'friends_count' => $user->friendsCount(),
        ]);
    }
}
