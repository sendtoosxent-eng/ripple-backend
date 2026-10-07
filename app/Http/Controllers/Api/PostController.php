<?php

namespace App\Http\Controllers\Api;

use App\Events\PostCommentAdded;
use App\Events\PostCreated;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostRepost;
use App\Models\PostReaction;
use App\Models\Conversation;
use App\Models\Status;
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

        $posts = Post::with(['user:id,name,username,avatar', 'reactions'])
            ->withCount(['likes', 'comments', 'reposts'])
            ->latest()
            ->paginate(20);

        $posts->getCollection()->transform(function ($post) use ($me) {
            $post->liked_by_me = $post->likes()->where('user_id', $me)->exists();
            $post->reposted_by_me = $post->reposts()->where('user_id', $me)->exists();
            $post->my_reaction = optional($post->reactions->firstWhere('user_id', $me))->emoji;
            $post->reaction_summary = $post->reactions->groupBy('emoji')->map(fn ($items, $emoji) => [
                'emoji' => $emoji,
                'count' => $items->count(),
            ])->values();
            unset($post->reactions);
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
            'media' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm|max:51200',
            'media_type' => 'nullable|in:image,video',
            'media_duration_ms' => 'nullable|integer|min:1|max:30000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (! $request->text && ! $request->hasFile('image') && ! $request->hasFile('media')) {
            return response()->json(['message' => 'Post needs text, a photo, or a video.'], 422);
        }

        if ($request->media_type === 'video' && ! $request->filled('media_duration_ms')) {
            return response()->json(['message' => 'Video duration is required.'], 422);
        }

        $payload = ['user_id' => $request->user()->id, 'text' => $request->text];

        if ($request->hasFile('image')) {
            $payload['image_path'] = CloudinaryUploader::upload($request->file('image'), 'posts');
        }

        if ($request->hasFile('media')) {
            $payload['media_type'] = $request->media_type;
            $payload['media_duration_ms'] = $request->media_duration_ms;
            $payload['media_path'] = CloudinaryUploader::upload(
                $request->file('media'),
                'posts',
                $request->media_type === 'video' ? 'video' : 'image'
            );
        }

        $post = Post::create($payload);
        $post->load('user:id,name,username,avatar');

        \App\Services\SafeBroadcast::send(new PostCreated($post));

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

    public function react(Request $request, Post $post)
    {
        $data = $request->validate(['emoji' => 'required|string|max:16']);
        $reaction = PostReaction::where('post_id', $post->id)->where('user_id', $request->user()->id)->first();

        if ($reaction && $reaction->emoji === $data['emoji']) {
            $reaction->delete();
            $mine = null;
        } else {
            PostReaction::updateOrCreate(
                ['post_id' => $post->id, 'user_id' => $request->user()->id],
                ['emoji' => $data['emoji']]
            );
            $mine = $data['emoji'];
        }

        $summary = $post->reactions()->get()->groupBy('emoji')->map(fn ($items, $emoji) => [
            'emoji' => $emoji,
            'count' => $items->count(),
        ])->values();

        return response()->json(['my_reaction' => $mine, 'reaction_summary' => $summary]);
    }

    public function share(Request $request, Post $post)
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $me = $request->user();
        abort_if((int) $data['user_id'] === $me->id, 422, 'Choose another person.');
        abort_unless($me->isFriendsWith((int) $data['user_id']), 403, 'You can only share posts with friends.');

        $conversation = $me->conversations()->where('is_group', false)
            ->whereHas('members', fn ($q) => $q->where('users.id', $data['user_id']))->first();
        if (! $conversation) {
            $conversation = Conversation::create(['is_group' => false, 'created_by' => $me->id]);
            $conversation->members()->attach([$me->id, $data['user_id']]);
        }

        $description = trim((string) $post->text) ?: ($post->media_type === 'video' ? 'Video post' : 'Photo post');
        $message = $conversation->messages()->create([
            'sender_id' => $me->id,
            'type' => 'text',
            'text' => "Shared @{$post->user->username}'s post:\n{$description}",
            'status' => 'sent',
        ]);
        $message->load(['sender:id,name,username,avatar', 'reactions']);
        \App\Services\SafeBroadcast::send(new MessageSent($message));
        Notifier::send((int) $data['user_id'], 'new_message', [
            'actor_id' => $me->id, 'actor_name' => $me->name,
            'conversation_id' => $conversation->id, 'preview' => 'Shared a post',
        ]);

        return response()->json(['conversation_id' => $conversation->id], 201);
    }

    public function shareToStatus(Request $request, Post $post)
    {
        $mediaPath = $post->media_path ?: $post->image_path;
        $type = $post->media_type ?: ($mediaPath ? 'image' : 'text');
        $status = Status::create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'text' => $post->text ?: "Shared @{$post->user->username}'s post",
            'media_path' => $mediaPath,
            'media_duration_ms' => $post->media_duration_ms,
            'background' => '#188B84',
            'expires_at' => now()->addDay(),
        ]);
        return response()->json($status, 201);
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

        \App\Services\SafeBroadcast::send(new PostCommentAdded($comment));

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
