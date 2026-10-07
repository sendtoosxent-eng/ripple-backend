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
        'client_message_id',
        'reply_to_id',
        'status_reply_id',
        'forwarded_from_id',

        'type',
        'text',
        'media_path',

        'file_name',
        'file_size',
        'mime_type',

        'voice_duration',

        'call_status',
        'call_duration',

        'waveform',
        'width',
        'height',

        'status',

        'edited_at',
        'deleted_for_everyone_at',
        'deleted_for_user_ids',
    ];

    /*
     * Do not expose the complete list of users
     * who deleted a message for themselves.
     */
    protected $hidden = [
        'deleted_for_user_ids',
    ];

    protected function casts(): array
    {
        return [
            'waveform' => 'array',

            'deleted_for_user_ids' => 'array',

            'edited_at' => 'datetime',

            'deleted_for_everyone_at' => 'datetime',
        ];
    }

    protected $appends = [
        'media_url',
        'reply_preview',
        'reaction_summary',
        'status_reply_preview',
        'delivery_summary',
        'hidden_for_me',
    ];

    public function conversation()
    {
        return $this->belongsTo(
            Conversation::class
        );
    }

    public function sender()
    {
        return $this->belongsTo(
            User::class,
            'sender_id'
        );
    }

    public function replyTo()
    {
        return $this->belongsTo(
            Message::class,
            'reply_to_id'
        );
    }

    public function forwardedFrom()
    {
        return $this->belongsTo(
            Message::class,
            'forwarded_from_id'
        );
    }

    public function statusReply()
    {
        return $this->belongsTo(
            Status::class,
            'status_reply_id'
        );
    }

    public function reactions()
    {
        return $this->hasMany(
            MessageReaction::class
        );
    }

    public function receipts()
    {
        return $this->hasMany(
            MessageReceipt::class
        );
    }

    public function getHiddenForMeAttribute(): bool
    {
        $userId = auth()->id();

        if (! $userId) {
            return false;
        }

        $hiddenFor = $this->deleted_for_user_ids ?? [];

        return in_array(
            (int) $userId,
            array_map(
                'intval',
                $hiddenFor
            ),
            true
        );
    }

    public function getDeliverySummaryAttribute(): array
    {
        if (! $this->relationLoaded('receipts')) {
            return [];
        }

        $total = $this->receipts->count();

        return [
            'total' => $total,

            'delivered' => $this
                ->receipts
                ->whereNotNull('delivered_at')
                ->count(),

            'read' => $this
                ->receipts
                ->whereNotNull('read_at')
                ->count(),
        ];
    }

    public function getStatusReplyPreviewAttribute()
    {
        if (
            ! $this->status_reply_id ||
            ! $this->relationLoaded('statusReply') ||
            ! $this->statusReply
        ) {
            return null;
        }

        $status = $this->statusReply;

        return [
            'id' => $status->id,
            'type' => $status->type,
            'text' => $status->text,
            'media_url' => $status->media_url,
            'background' => $status->background,
        ];
    }

    public function getMediaUrlAttribute()
    {
        if ($this->deleted_for_everyone_at) {
            return null;
        }

        return $this->type === 'image'
            ? \App\Services\CloudinaryUploader::resized(
                $this->media_path,
                1000
            )
            : $this->media_path;
    }

    public function getReplyPreviewAttribute()
    {
        if (
            ! $this->reply_to_id ||
            ! $this->relationLoaded('replyTo') ||
            ! $this->replyTo
        ) {
            return null;
        }

        $original = $this->replyTo;

        if ($original->deleted_for_everyone_at) {
            return [
                'id' => $original->id,
                'sender_name' => $original->sender?->name,
                'preview' => 'This message was deleted',
            ];
        }

        return [
            'id' => $original->id,

            'sender_name' =>
                $original->sender?->name,

            'preview' =>
                $original->type === 'text'
                    ? $original->text
                    : match ($original->type) {
                        'image' => 'Photo',

                        'file' =>
                            $original->file_name
                            ?? 'Document',

                        'call' => 'Call',

                        default =>
                            'Voice message',
                    },
        ];
    }

    public function getReactionSummaryAttribute()
    {
        if (
            ! $this->relationLoaded('reactions')
        ) {
            return [];
        }

        return $this
            ->reactions
            ->groupBy('emoji')
            ->map(
                fn ($group, $emoji) => [
                    'emoji' => $emoji,

                    'count' =>
                        $group->count(),

                    'user_ids' =>
                        $group
                            ->pluck('user_id')
                            ->values(),
                ]
            )
            ->values();
    }
}