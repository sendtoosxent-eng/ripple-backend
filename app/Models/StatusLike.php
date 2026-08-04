<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusLike extends Model
{
    protected $fillable = ['status_id', 'user_id'];
}
