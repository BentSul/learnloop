<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's link to their Canvas account (v0.8 §3.1, §3.7).
 * Tokens are encrypted at rest and hidden from serialisation; never log or return them.
 */
class CanvasConnection extends Model
{
    protected $fillable = [
        'user_id', 'canvas_user_id', 'adapter', 'access_token', 'refresh_token',
        'expires_at', 'connected_at', 'revoked_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
