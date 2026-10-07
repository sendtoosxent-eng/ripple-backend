<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'reposted_from_id', 'type', 'text', 'media_path', 'media_duration_ms', 'background', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected $appends = ['media_url'];

    public function getMediaUrlAttribute()
    {
        return $this->type === 'image'
            ? \App\Services\CloudinaryUploader::resized($this->media_path, 900)
            : $this->media_path;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function views()
    {
        return $this->hasMany(StatusView::class);
    }

    public function likes()
    {
        return $this->hasMany(StatusLike::class);
    }

    public function reactions()
    {
        return $this->hasMany(StatusReaction::class);
    }

    public function repostedFrom()
    {
        return $this->belongsTo(self::class, 'reposted_from_id');
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
