<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

// Only members of a conversation can subscribe to its private channel.
// This is what keeps chats private between friends.
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation && $conversation->members->contains($user->id);
});
