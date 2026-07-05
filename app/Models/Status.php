<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'type', 'text', 'media_path', 'background', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected $appends = ['media_url'];

    public function getMediaUrlAttribute()
    {
        return $this->media_path;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function views()
    {
        return $this->hasMany(StatusView::class);
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
