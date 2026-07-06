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
}
