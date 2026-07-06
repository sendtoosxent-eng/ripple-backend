<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'reply_to_id',
        'type',
        'text',
        'media_path',
        'voice_duration',
        'waveform',
        'width',
        'height',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'waveform' => 'array',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    // Appends full public URLs / computed fields so the frontend doesn't build them itself
    protected $appends = ['media_url', 'reply_preview', 'reaction_summary'];

    public function getMediaUrlAttribute()
    {
        return $this->type === 'image'
            ? \App\Services\CloudinaryUploader::resized($this->media_path, 1000)
            : $this->media_path;
    }

    public function getReplyPreviewAttribute()
    {
        if (! $this->reply_to_id || ! $this->relationLoaded('replyTo') || ! $this->replyTo) {
            return null;
        }

        $original = $this->replyTo;

        return [
            'id' => $original->id,
            'sender_name' => $original->sender?->name,
            'preview' => $original->type === 'text'
                ? $original->text
                : ($original->type === 'image' ? 'Photo' : 'Voice message'),
        ];
    }

    public function getReactionSummaryAttribute()
    {
        if (! $this->relationLoaded('reactions')) {
            return [];
        }

        return $this->reactions
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'user_ids' => $group->pluck('user_id')->values(),
            ])
            ->values();
    }
}
