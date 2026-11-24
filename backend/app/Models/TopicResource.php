<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link students can use to study a topic (video, reading, practice site). */
class TopicResource extends Model
{
    public const KINDS = ['video', 'reading', 'practice', 'other'];

    protected $attributes = ['status' => 'published', 'source' => 'lecturer', 'position' => 0];

    protected $fillable = ['topic_id', 'kind', 'title', 'url', 'note', 'status', 'source', 'external_id', 'position', 'added_by'];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** @return array<string, mixed> what students see */
    public function toLearnerArray(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'title' => $this->title, 'url' => $this->url, 'note' => $this->note];
    }

    /** @return array<string, mixed> what lecturers see */
    public function toLecturerArray(): array
    {
        return $this->toLearnerArray() + [
            'status' => $this->status, 'source' => $this->source, 'position' => $this->position,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
