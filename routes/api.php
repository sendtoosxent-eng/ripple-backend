<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PostController;
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
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::post('/conversations/{conversation}/read', [MessageController::class, 'markRead']);
    Route::patch('/conversations/{conversation}/mute', [ConversationController::class, 'toggleMute']);
    Route::post('/conversations/{conversation}/leave', [ConversationController::class, 'leave']);
    Route::post('/messages/{message}/react', [MessageController::class, 'react']);

    Route::get('/statuses', [StatusController::class, 'index']);
    Route::post('/statuses', [StatusController::class, 'store']);
    Route::post('/statuses/{status}/view', [StatusController::class, 'markViewed']);
    Route::delete('/statuses/{status}', [StatusController::class, 'destroy']);
    Route::post('/statuses/{status}/reply', [StatusController::class, 'reply']);

    Route::get('/friend-requests', [FriendController::class, 'index']);
    Route::post('/friend-requests', [FriendController::class, 'store']);
    Route::post('/friend-requests/{friendRequest}/accept', [FriendController::class, 'accept']);
    Route::post('/friend-requests/{friendRequest}/reject', [FriendController::class, 'reject']);
    Route::get('/friends', [FriendController::class, 'friends']);
    Route::get('/friend-status/{user}', [FriendController::class, 'statusWith']);

    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::post('/posts/{post}/like', [PostController::class, 'toggleLike']);
    Route::post('/posts/{post}/repost', [PostController::class, 'toggleRepost']);
    Route::get('/posts/{post}/comments', [PostController::class, 'comments']);
    Route::post('/posts/{post}/comments', [PostController::class, 'addComment']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
});

// Lets Laravel Echo verify a user is allowed to listen to a private channel.
Broadcast::routes(['middleware' => ['auth:sanctum']]);
