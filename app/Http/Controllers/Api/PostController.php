<?php

namespace App\Http\Controllers\Api;

use App\Events\PostCommentAdded;
use App\Events\PostCreated;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostRepost;
use App\Services\CloudinaryUploader;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PostController extends Controller
{
    // GET /api/posts — everyone's posts, newest first
    public function index(Request $request)
    {
        $me = $request->user()->id;

        $posts = Post::with('user:id,name,username,avatar')
            ->withCount(['likes', 'comments', 'reposts'])
            ->latest()
            ->paginate(20);

        $posts->getCollection()->transform(function ($post) use ($me) {
            $post->liked_by_me = $post->likes()->where('user_id', $me)->exists();
            $post->reposted_by_me = $post->reposts()->where('user_id', $me)->exists();
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

        broadcast(new PostCreated($post))->toOthers();

        // Notify friends that this person posted
        $author = $request->user();
        foreach ($author->friendIds() as $friendId) {
            Notifier::send($friendId, 'new_post', [
                'actor_id' => $author->id,
                'actor_name' => $author->name,
                'actor_avatar' => $author->avatar_url,
                'post_id' => $post->id,
                'preview' => \Illuminate\Support\Str::limit($post->text ?? 'shared a photo', 60),
            ]);
        }

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

            if ($post->user_id !== $request->user()->id) {
                Notifier::send($post->user_id, 'post_liked', [
                    'actor_id' => $request->user()->id,
                    'actor_name' => $request->user()->name,
                    'actor_avatar' => $request->user()->avatar_url,
                    'post_id' => $post->id,
                ]);
            }
        }

        return response()->json(['liked' => $liked, 'likes_count' => $post->likes()->count()]);
    }

    // POST /api/posts/{post}/repost — toggle (count only, doesn't duplicate into the feed)
    public function toggleRepost(Request $request, Post $post)
    {
        $existing = PostRepost::where('post_id', $post->id)->where('user_id', $request->user()->id)->first();

        if ($existing) {
            $existing->delete();
            $reposted = false;
        } else {
            PostRepost::create(['post_id' => $post->id, 'user_id' => $request->user()->id]);
            $reposted = true;

            if ($post->user_id !== $request->user()->id) {
                Notifier::send($post->user_id, 'post_reposted', [
                    'actor_id' => $request->user()->id,
                    'actor_name' => $request->user()->name,
                    'actor_avatar' => $request->user()->avatar_url,
                    'post_id' => $post->id,
                ]);
            }
        }

        return response()->json(['reposted' => $reposted, 'reposts_count' => $post->reposts()->count()]);
    }

    // GET /api/posts/{post}/comments
    public function comments(Post $post)
    {
        return response()->json(
            $post->comments()->with('user:id,name,username,avatar')->oldest()->get()
        );
    }

    // POST /api/posts/{post}/comments
    public function addComment(Request $request, Post $post)
    {
        $data = $request->validate(['text' => 'required|string|max:500']);

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'text' => $data['text'],
        ]);
        $comment->load('user:id,name,username,avatar');

        broadcast(new PostCommentAdded($comment))->toOthers();

        if ($post->user_id !== $request->user()->id) {
            Notifier::send($post->user_id, 'post_commented', [
                'actor_id' => $request->user()->id,
                'actor_name' => $request->user()->name,
                'actor_avatar' => $request->user()->avatar_url,
                'post_id' => $post->id,
                'preview' => \Illuminate\Support\Str::limit($data['text'], 60),
            ]);
        }

        return response()->json($comment, 201);
    }

    // DELETE /api/posts/{post}
    public function destroy(Request $request, Post $post)
    {
        abort_unless($post->user_id === $request->user()->id, 403);
        $post->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
