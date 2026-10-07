<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusReaction extends Model
{
    protected $fillable = ['status_id', 'user_id', 'emoji'];
}
