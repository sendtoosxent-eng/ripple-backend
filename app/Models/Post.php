<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['user_id', 'text', 'image_path', 'media_path', 'media_type', 'media_duration_ms'];

    protected $appends = ['image_url', 'media_url'];

    public function getImageUrlAttribute()
    {
        return $this->image_path
            ? \App\Services\CloudinaryUploader::resized($this->image_path, 1000)
            : null;
    }

    public function getMediaUrlAttribute()
    {
        $path = $this->media_path ?: $this->image_path;
        return $this->media_type === 'video'
            ? $path
            : \App\Services\CloudinaryUploader::resized($path, 1000);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function likes()
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments()
    {
        return $this->hasMany(PostComment::class);
    }

    public function reposts()
    {
        return $this->hasMany(PostRepost::class);
    }

    public function reactions()
    {
        return $this->hasMany(PostReaction::class);
    }
}
