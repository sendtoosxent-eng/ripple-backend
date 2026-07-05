<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Status;
use App\Models\StatusView;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StatusController extends Controller
{
    // GET /api/statuses — everyone's active statuses, grouped by user, my own first
    public function index(Request $request)
    {
        $me = $request->user();

        $statuses = Status::active()
            ->with(['user:id,name,username,avatar', 'views'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('user_id')
            ->map(function ($group) use ($me) {
                $user = $group->first()->user;
                return [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'avatar_url' => $user->avatar_url,
                    ],
                    'all_viewed' => $group->every(fn ($s) => $s->views->contains('viewer_id', $me->id)),
                    'statuses' => $group->values()->map(fn ($s) => [
                        'id' => $s->id,
                        'type' => $s->type,
                        'text' => $s->text,
                        'media_url' => $s->media_url,
                        'background' => $s->background,
                        'created_at' => $s->created_at,
                        'viewed_by_me' => $s->views->contains('viewer_id', $me->id),
                        'view_count' => $s->views->count(),
                    ]),
                ];
            })
            ->values();

        return response()->json($statuses);
    }

    // POST /api/statuses — post a text or image status
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:text,image',
            'text' => 'required_if:type,text|nullable|string|max:500',
            'image' => 'required_if:type,image|nullable|image|max:8192',
            'background' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = [
            'user_id' => $request->user()->id,
            'type' => $request->type,
            'text' => $request->text,
            'background' => $request->background ?? '#25D366',
            'expires_at' => now()->addDay(),
        ];

        if ($request->type === 'image' && $request->hasFile('image')) {
            $payload['media_path'] = CloudinaryUploader::upload($request->file('image'), 'statuses');
        }

        $status = Status::create($payload);

        return response()->json($status->load('user:id,name,username,avatar'), 201);
    }

    // POST /api/statuses/{status}/view — mark as seen by me
    public function markViewed(Request $request, Status $status)
    {
        StatusView::firstOrCreate(
            ['status_id' => $status->id, 'viewer_id' => $request->user()->id],
            ['viewed_at' => now()],
        );

        return response()->json(['message' => 'Viewed']);
    }

    // DELETE /api/statuses/{status} — remove my own status early
    public function destroy(Request $request, Status $status)
    {
        abort_unless($status->user_id === $request->user()->id, 403);
        $status->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
