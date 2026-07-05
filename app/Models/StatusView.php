<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusView extends Model
{
    public $timestamps = false;

    protected $fillable = ['status_id', 'viewer_id', 'viewed_at'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }
}
