<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedUser;
use App\Models\User;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    // GET /api/users — everyone except me AND anyone blocking/blocked, for the "start new chat" screen
    public function index(Request $request)
    {
        $me = $request->user();
        $blockedIds = BlockedUser::where('blocker_id', $me->id)->pluck('blocked_id')
            ->merge(BlockedUser::where('blocked_id', $me->id)->pluck('blocker_id'));

        $users = User::where('id', '!=', $me->id)
            ->whereNotIn('id', $blockedIds)
            ->select('id', 'name', 'username', 'avatar', 'online')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    // GET /api/users/{user} — view another person's public profile
    public function show(Request $request, User $user)
    {
        $me = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar_url' => $user->avatar_url,
            'cover_photo_url' => $user->cover_photo_url,
            'bio' => $user->bio,
            'status' => $user->status,
            'online' => $user->online,
            'friends_count' => $user->friendsCount(),
            'posts_count' => $user->postsCount(),
            'is_blocked_by_me' => $me->hasBlocked($user->id),
        ]);
    }

    // PATCH /api/me — edit profile
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'username' => 'sometimes|required|string|max:50|alpha_dash|unique:users,username,' . $user->id,
            'bio' => 'nullable|string|max:500',
            'status' => 'nullable|string|max:100',
            'avatar' => 'nullable|image|max:4096',
            'cover_photo' => 'nullable|image|max:6144',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'username', 'bio', 'status']);

        if ($request->hasFile('avatar')) {
            $data['avatar'] = CloudinaryUploader::upload($request->file('avatar'), 'avatars');
        }

        if ($request->hasFile('cover_photo')) {
            $data['cover_photo'] = CloudinaryUploader::upload($request->file('cover_photo'), 'covers');
        }

        $user->update($data);

        return response()->json($user->fresh()->append(['friends_count', 'posts_count']));
    }

    // DELETE /api/me — permanently delete my account
    public function destroy(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Account deleted']);
    }

    // POST /api/users/{user}/block
    public function block(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot block yourself.');

        BlockedUser::firstOrCreate([
            'blocker_id' => $request->user()->id,
            'blocked_id' => $user->id,
        ]);

        return response()->json(['message' => 'Blocked']);
    }

    // POST /api/users/{user}/unblock
    public function unblock(Request $request, User $user)
    {
        BlockedUser::where('blocker_id', $request->user()->id)->where('blocked_id', $user->id)->delete();

        return response()->json(['message' => 'Unblocked']);
    }
}
