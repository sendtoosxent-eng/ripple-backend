<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['user_id', 'type', 'data', 'read'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read' => 'boolean',
        ];
    }
}
