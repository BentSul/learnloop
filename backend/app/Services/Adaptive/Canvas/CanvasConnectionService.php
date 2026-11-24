<?php

namespace App\Services\Adaptive\Canvas;

use App\Models\CanvasConnection;
use App\Models\User;
use App\Services\Adaptive\Lecturer\LecturerException;

/**
 * Links a LearnLoop account to the user's own Canvas account with a Canvas access token
 * (v0.8 §3.1 Tier 2). The token is checked against Canvas before it is stored, is stored
 * encrypted, and is never returned by the API.
 */
class CanvasConnectionService
{
    public function __construct(private readonly CanvasClient $client)
    {
    }

    /** @return array{connected: bool, adapter: string, oauth_available: bool, canvas_user_id?: string, connected_at?: string} */
    public function status(User $user): array
    {
        $connection = CanvasConnection::where('user_id', $user->id)->whereNull('revoked_at')->first();
        $adapter = (string) config('adaptive.canvas.adapter', 'fixture');
        $oauth = app(CanvasOAuthService::class)->enabled();

        return $connection === null
            ? ['connected' => false, 'adapter' => $adapter, 'oauth_available' => $oauth]
            : [
                'connected' => true,
                'adapter' => $adapter,
                'oauth_available' => $oauth,
                'canvas_user_id' => $connection->canvas_user_id,
                'connected_at' => $connection->connected_at?->toIso8601String(),
            ];
    }

    /** @return array<string, mixed> status after connecting */
    public function connect(User $user, string $token): array
    {
        if (! in_array(config('adaptive.canvas.adapter'), ['token', 'hybrid'], true)) {
            throw new CanvasNotConnected('Canvas tokens are not enabled on this server (CANVAS_ADAPTER is not "token" or "hybrid").');
        }

        $token = trim($token);
        try {
            $self = $this->client->getObject($token, '/api/v1/users/self');
        } catch (CanvasNotConnected) {
            $self = null;
        }
        if ($self === null || ! isset($self['id'])) {
            throw LecturerException::invalid('Canvas did not accept this token. Check that you copied all of it and that it has not expired.');
        }

        $canvasUserId = (string) $self['id'];
        $other = CanvasConnection::where('canvas_user_id', $canvasUserId)->where('user_id', '!=', $user->id)->first();
        if ($other !== null && $other->revoked_at === null) {
            throw LecturerException::invalid('This Canvas account is already linked to another LearnLoop account. Disconnect Canvas in that account first.');
        }
        // A disconnected link no longer owns the Canvas account; free it for this user.
        $other?->delete();

        CanvasConnection::updateOrCreate(['user_id' => $user->id], [
            'canvas_user_id' => $canvasUserId,
            'adapter' => 'token',
            'access_token' => $token,
            'refresh_token' => null,
            'expires_at' => null,
            'connected_at' => now(),
            'revoked_at' => null,
        ]);

        return $this->status($user);
    }

    /** Forgets the token. Evidence already synced stays (it is the learner's history). */
    public function disconnect(User $user): void
    {
        CanvasConnection::where('user_id', $user->id)->whereNull('revoked_at')
            ->update(['access_token' => null, 'refresh_token' => null, 'revoked_at' => now()]);
    }
}
