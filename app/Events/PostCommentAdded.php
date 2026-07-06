<?php

namespace App\Events;

use App\Models\PostComment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostCommentAdded implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public PostComment $comment) {}

    // Public channel — comments on a post aren't private info, matches the public "posts" feed channel
    public function broadcastOn(): array
    {
        return [new Channel('post.' . $this->comment->post_id)];
    }

    public function broadcastAs(): string
    {
        return 'comment.added';
    }

    public function broadcastWith(): array
    {
        return ['comment' => $this->comment];
    }
}
