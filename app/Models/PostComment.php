<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostComment extends Model
{
    protected $fillable = ['post_id', 'user_id', 'text'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
