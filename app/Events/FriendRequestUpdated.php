<?php

namespace App\Events;

use App\Models\FriendRequest;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FriendRequestUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public FriendRequest $friendRequest,
        public int $actorId,
    ) {
        $this->friendRequest->load('sender:id,name,username,avatar', 'receiver:id,name,username,avatar');
    }

    public function broadcastOn(): array
    {
        // Notify whichever side didn't perform the action
        $notifyUserId = $this->actorId === $this->friendRequest->sender_id
            ? $this->friendRequest->receiver_id
            : $this->friendRequest->sender_id;

        return [new PrivateChannel('user.' . $notifyUserId)];
    }

    public function broadcastAs(): string
    {
        return 'friend-request.updated';
    }

    public function broadcastWith(): array
    {
        return ['friend_request' => $this->friendRequest];
    }
}
