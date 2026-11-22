<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A LearnLoop course, linked to one Canvas course (v0.8 §3.7). */
class Course extends Model
{
    protected $fillable = ['name', 'code'];

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function canvasLink(): HasOne
    {
        return $this->hasOne(CourseLink::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }
}
