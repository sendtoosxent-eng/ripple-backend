<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Authenticated (Sanctum token required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/presence', [AuthController::class, 'presence']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [UserController::class, 'update']);
    Route::delete('/me', [UserController::class, 'destroy']);

    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::post('/users/{user}/block', [UserController::class, 'block']);
    Route::post('/users/{user}/unblock', [UserController::class, 'unblock']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);

    Route::get(
        '/conversations/{conversation}/typing',
        [\App\Http\Controllers\Api\TypingController::class, 'index']
    );

    Route::post(
        '/conversations/{conversation}/typing',
        [\App\Http\Controllers\Api\TypingController::class, 'update']
    )->middleware('throttle:90,1');

    Route::get(
        '/conversations/{conversation}/messages',
        [MessageController::class, 'index']
    );

    Route::post(
        '/conversations/{conversation}/messages',
        [MessageController::class, 'store']
    );

    Route::post(
        '/conversations/{conversation}/calls/notify',
        [MessageController::class, 'notifyIncomingCall']
    );

    Route::post(
        '/conversations/{conversation}/read',
        [MessageController::class, 'markRead']
    );

    Route::patch(
        '/conversations/{conversation}/mute',
        [ConversationController::class, 'toggleMute']
    );

    Route::get(
        '/conversations/{conversation}/details',
        [ConversationController::class, 'details']
    );

    Route::post(
        '/conversations/{conversation}/members',
        [ConversationController::class, 'addMembers']
    );

    Route::delete(
        '/conversations/{conversation}/members/{user}',
        [ConversationController::class, 'removeMember']
    );

    Route::post(
        '/conversations/{conversation}/leave',
        [ConversationController::class, 'leave']
    );

    Route::post(
        '/messages/{message}/react',
        [MessageController::class, 'react']
    );

    // Statuses
    Route::get('/statuses', [StatusController::class, 'index']);
    Route::post('/statuses', [StatusController::class, 'store']);
    Route::post('/statuses/{status}/view', [StatusController::class, 'markViewed']);
    Route::delete('/statuses/{status}', [StatusController::class, 'destroy']);
    Route::post('/statuses/{status}/reply', [StatusController::class, 'reply']);
    Route::post('/statuses/{status}/like', [StatusController::class, 'toggleLike']);
    Route::post('/statuses/{status}/react', [StatusController::class, 'react']);
    Route::post('/statuses/{status}/repost', [StatusController::class, 'repost']);

    // Friends
    Route::get('/friend-requests', [FriendController::class, 'index']);
    Route::post('/friend-requests', [FriendController::class, 'store']);

    Route::post(
        '/friend-requests/{friendRequest}/accept',
        [FriendController::class, 'accept']
    );

    Route::post(
        '/friend-requests/{friendRequest}/reject',
        [FriendController::class, 'reject']
    );

    Route::get('/friends', [FriendController::class, 'friends']);
    Route::get('/friend-status/{user}', [FriendController::class, 'statusWith']);

    // Posts
    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::post('/posts/{post}/like', [PostController::class, 'toggleLike']);
    Route::post('/posts/{post}/react', [PostController::class, 'react']);
    Route::post('/posts/{post}/repost', [PostController::class, 'toggleRepost']);
    Route::post('/posts/{post}/share', [PostController::class, 'share']);
    Route::post('/posts/{post}/share-to-status', [PostController::class, 'shareToStatus']);
    Route::get('/posts/{post}/comments', [PostController::class, 'comments']);
    Route::post('/posts/{post}/comments', [PostController::class, 'addComment']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::get(
        '/notifications/unread-count',
        [NotificationController::class, 'unreadCount']
    );

    Route::get(
        '/notification-preferences',
        [NotificationController::class, 'preferences']
    );

    Route::patch(
        '/notification-preferences',
        [NotificationController::class, 'updatePreferences']
    );

    // Existing browser/web push notifications
    Route::get(
        '/push/vapid-public-key',
        [PushSubscriptionController::class, 'key']
    );

    Route::post(
        '/push/subscriptions',
        [PushSubscriptionController::class, 'store']
    );

    Route::delete(
        '/push/subscriptions',
        [PushSubscriptionController::class, 'destroy']
    );

    // Mobile iOS / Android push notification devices
    Route::post(
        '/devices/register',
        [DeviceController::class, 'register']
    );

    Route::delete(
        '/devices/unregister',
        [DeviceController::class, 'unregister']
    );
});

// Lets Laravel Echo verify a user is allowed to listen to a private channel.
Broadcast::routes([
    'middleware' => ['auth:sanctum']
]);
