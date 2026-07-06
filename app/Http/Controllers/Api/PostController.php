<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostLike;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PostController extends Controller
{
    // GET /api/posts — everyone's posts, newest first
    public function index(Request $request)
    {
        $me = $request->user()->id;

        $posts = Post::with('user:id,name,username,avatar')
            ->withCount('likes')
            ->latest()
            ->paginate(20);

        $posts->getCollection()->transform(function ($post) use ($me) {
            $post->liked_by_me = $post->likes()->where('user_id', $me)->exists();
            return $post;
        });

        return response()->json($posts);
    }

    // POST /api/posts
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'nullable|string|max:500',
            'image' => 'nullable|image|max:8192',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (! $request->text && ! $request->hasFile('image')) {
            return response()->json(['message' => 'Post needs text or an image.'], 422);
        }

        $payload = ['user_id' => $request->user()->id, 'text' => $request->text];

        if ($request->hasFile('image')) {
            $payload['image_path'] = CloudinaryUploader::upload($request->file('image'), 'posts');
        }

        $post = Post::create($payload);
        $post->load('user:id,name,username,avatar');

        return response()->json($post, 201);
    }

    // POST /api/posts/{post}/like — toggle
    public function toggleLike(Request $request, Post $post)
    {
        $existing = PostLike::where('post_id', $post->id)->where('user_id', $request->user()->id)->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            PostLike::create(['post_id' => $post->id, 'user_id' => $request->user()->id]);
            $liked = true;
        }

        return response()->json(['liked' => $liked, 'likes_count' => $post->likes()->count()]);
    }

    // DELETE /api/posts/{post}
    public function destroy(Request $request, Post $post)
    {
        abort_unless($post->user_id === $request->user()->id, 403);
        $post->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
