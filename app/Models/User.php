<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'avatar',
        'cover_photo',
        'status',
        'bio',
        'online',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'online' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected $appends = ['avatar_url', 'cover_photo_url'];

    public function getAvatarUrlAttribute()
    {
        return \App\Services\CloudinaryUploader::resized($this->avatar, 200);
    }

    public function getCoverPhotoUrlAttribute()
    {
        return \App\Services\CloudinaryUploader::resized($this->cover_photo, 800);
    }

    public function getFriendsCountAttribute(): int
    {
        return $this->friendsCount();
    }

    public function getPostsCountAttribute(): int
    {
        return $this->postsCount();
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot(['muted', 'last_read_at'])
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function sentFriendRequests()
    {
        return $this->hasMany(FriendRequest::class, 'sender_id');
    }

    public function receivedFriendRequests()
    {
        return $this->hasMany(FriendRequest::class, 'receiver_id');
    }

    public function friendsCount(): int
    {
        return FriendRequest::where('status', 'accepted')
            ->where(function ($q) {
                $q->where('sender_id', $this->id)->orWhere('receiver_id', $this->id);
            })
            ->count();
    }

    public function postsCount(): int
    {
        return Post::where('user_id', $this->id)->count();
    }

    public function isFriendsWith(int $userId): bool
    {
        return FriendRequest::where('status', 'accepted')
            ->where(function ($q) use ($userId) {
                $q->where('sender_id', $this->id)->where('receiver_id', $userId);
            })
            ->orWhere(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->where('receiver_id', $this->id);
            })
            ->exists();
    }

    public function friendIds(): array
    {
        $accepted = FriendRequest::where('status', 'accepted')
            ->where(function ($q) {
                $q->where('sender_id', $this->id)->orWhere('receiver_id', $this->id);
            })
            ->get();

        return $accepted->map(fn ($fr) => $fr->sender_id === $this->id ? $fr->receiver_id : $fr->sender_id)->all();
    }

    public function hasBlocked(int $userId): bool
    {
        return BlockedUser::where('blocker_id', $this->id)->where('blocked_id', $userId)->exists();
    }

    public function isBlockedBy(int $userId): bool
    {
        return BlockedUser::where('blocker_id', $userId)->where('blocked_id', $this->id)->exists();
    }
}
