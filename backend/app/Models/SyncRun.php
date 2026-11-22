<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Canvas/Klenam sync for a user and course (v0.8 §3.6). */
class SyncRun extends Model
{
    protected $fillable = ['user_id', 'course_id', 'source', 'started_at', 'finished_at', 'status', 'summary', 'error'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }
}
