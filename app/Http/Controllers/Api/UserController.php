<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    // GET /api/users — everyone except me, for the "start new chat" screen
    public function index(Request $request)
    {
        $users = User::where('id', '!=', $request->user()->id)
            ->select('id', 'name', 'username', 'avatar', 'online')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    // GET /api/users/{user} — view another person's public profile
    public function show(User $user)
    {
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar_url' => $user->avatar_url,
            'cover_photo_url' => $user->cover_photo_url,
            'bio' => $user->bio,
            'status' => $user->status,
            'online' => $user->online,
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

        return response()->json($user->fresh());
    }
}
