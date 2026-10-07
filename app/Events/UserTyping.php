<?php
namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UserTyping implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    public function __construct(public int $conversationId, public int $userId, public string $name, public bool $isTyping) {}
    public function broadcastOn(): array { return [new PrivateChannel('conversation.'.$this->conversationId)]; }
    public function broadcastAs(): string { return 'user.typing'; }
    public function broadcastWith(): array { return ['user_id' => $this->userId, 'name' => $this->name, 'is_typing' => $this->isTyping]; }
}
