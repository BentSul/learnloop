<?php

namespace App\Services\Adaptive\Canvas;

use App\Models\CanvasConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Sign in with Canvas" / "Connect with Canvas" through a Canvas developer key (OAuth2).
 * Canvas API policy requires OAuth when an app is used by more than its developer; personal
 * access tokens remain for testing.
 *
 * Flow: start() returns Canvas's authorise URL with a one-time state → Canvas redirects to
 * /api/canvas/oauth/callback → complete() exchanges the code, stores the tokens (encrypted)
 * on the user's canvas_connections row with adapter "token", so TokenCanvasGateway reads
 * Canvas exactly as with a personal token. Access tokens last an hour; freshToken() renews them.
 */
class CanvasOAuthService
{
    private const STATE_MINUTES = 10;

    public function __construct(private readonly CanvasClient $client)
    {
    }

    public function enabled(): bool
    {
        return in_array(config('adaptive.canvas.adapter'), ['token', 'hybrid'], true)
            && filled(config('adaptive.canvas.oauth.client_id'))
            && filled(config('adaptive.canvas.oauth.client_secret'))
            && filled(config('adaptive.canvas.base_url'));
    }

    /** @param 'login'|'connect' $mode */
    public function start(string $mode, ?User $user = null): string
    {
        if (! $this->enabled()) {
            throw new CanvasNotConnected('Sign in with Canvas is not set up on this server yet.');
        }

        $state = Str::random(48);
        Cache::put("canvas-oauth:{$state}", ['mode' => $mode, 'user_id' => $user?->id], now()->addMinutes(self::STATE_MINUTES));

        return $this->client->baseUrl().'/login/oauth2/auth?'.http_build_query([
            'client_id' => config('adaptive.canvas.oauth.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'purpose' => 'LearnLoop',
        ]);
    }

    /**
     * Handles Canvas's redirect. Returns where to send the browser: a SPA path, with the new
     * LearnLoop API token in the URL fragment for a sign-in (fragments never reach servers).
     */
    public function complete(?string $code, ?string $state, ?string $error): string
    {
        $pending = $state ? Cache::pull("canvas-oauth:{$state}") : null; // one-time use
        if ($pending === null) {
            return '/login?canvas_error=expired';
        }
        $back = $pending['mode'] === 'connect' ? '/canvas' : '/login';
        if ($error !== null || blank($code)) {
            return "{$back}?canvas_error=denied";
        }

        try {
            $tokens = $this->exchange(['grant_type' => 'authorization_code', 'code' => $code]);
        } catch (CanvasUnavailable|CanvasNotConnected) {
            return "{$back}?canvas_error=failed";
        }
        $canvasUserId = (string) ($tokens['user']['id'] ?? '');
        if ($canvasUserId === '' || blank($tokens['access_token'] ?? null)) {
            return "{$back}?canvas_error=failed";
        }

        return $pending['mode'] === 'connect'
            ? $this->completeConnect((int) $pending['user_id'], $canvasUserId, $tokens)
            : $this->completeLogin($canvasUserId, $tokens);
    }

    /** A valid access token for this connection, renewing it first when it is about to expire. */
    public function freshToken(CanvasConnection $connection): ?string
    {
        if ($connection->refresh_token === null || $connection->expires_at === null
            || $connection->expires_at->isAfter(now()->addSeconds(60))) {
            return $connection->access_token ?: null;
        }

        return Cache::lock("canvas-refresh:{$connection->id}", 10)->block(10, function () use ($connection) {
            $connection->refresh();
            if ($connection->expires_at?->isAfter(now()->addSeconds(60))) {
                return $connection->access_token; // another request renewed it meanwhile
            }

            try {
                $tokens = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
            } catch (CanvasNotConnected) {
                // Refresh token revoked (e.g. the user removed LearnLoop in Canvas settings).
                $connection->update(['access_token' => null, 'refresh_token' => null, 'revoked_at' => now()]);

                return null;
            }

            $connection->update([
                'access_token' => $tokens['access_token'],
                'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            ]);

            return $connection->access_token;
        });
    }

    private function completeConnect(int $userId, string $canvasUserId, array $tokens): string
    {
        $other = CanvasConnection::where('canvas_user_id', $canvasUserId)->where('user_id', '!=', $userId)->first();
        if ($other !== null && $other->revoked_at === null) {
            return '/canvas?canvas_error=already_linked';
        }

        DB::transaction(function () use ($other, $userId, $canvasUserId, $tokens) {
            $other?->delete();
            $this->store($userId, $canvasUserId, $tokens);
        });

        return '/canvas?canvas_connected=1';
    }

    private function completeLogin(string $canvasUserId, array $tokens): string
    {
        // Already linked (even if disconnected since): proving the Canvas account signs that user in.
        $connection = CanvasConnection::where('canvas_user_id', $canvasUserId)->first();
        $user = $connection?->user;

        if ($user === null) {
            $email = $this->canvasEmail($tokens['access_token'], $canvasUserId);
            if (User::where('email', $email)->exists()) {
                // Never attach Canvas to an existing password account automatically: LearnLoop
                // does not verify emails, so that account may not belong to this person.
                return '/login?canvas_error=email_in_use';
            }

            $user = new User();
            $user->forceFill([
                'name' => (string) ($tokens['user']['name'] ?? 'Canvas user'),
                'email' => $email,
                'password' => Hash::make(Str::random(64)), // signs in with Canvas only
                'role' => 'student',
            ])->save();
        }

        $this->store($user->id, $canvasUserId, $tokens);
        $apiToken = $user->createToken('canvas_login')->plainTextToken;

        return '/auth/canvas#token='.rawurlencode($apiToken);
    }

    private function store(int $userId, string $canvasUserId, array $tokens): void
    {
        CanvasConnection::updateOrCreate(['user_id' => $userId], [
            'canvas_user_id' => $canvasUserId,
            'adapter' => 'token',
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
            'connected_at' => now(),
            'revoked_at' => null,
        ]);
    }

    /** Email from the Canvas profile; a placeholder when Canvas hides it. */
    private function canvasEmail(string $accessToken, string $canvasUserId): string
    {
        try {
            $profile = $this->client->getObject($accessToken, '/api/v1/users/self/profile') ?? [];
        } catch (CanvasUnavailable|CanvasNotConnected) {
            $profile = [];
        }
        foreach (['primary_email', 'login_id'] as $field) {
            $value = strtolower(trim((string) ($profile[$field] ?? '')));
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return "canvas-{$canvasUserId}@users.canvas.invalid";
    }

    /** @return array<string, mixed> Canvas token response */
    private function exchange(array $grant): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout((int) config('adaptive.canvas.timeout_seconds', 15))
                ->post($this->client->baseUrl().'/login/oauth2/token', $grant + [
                    'client_id' => config('adaptive.canvas.oauth.client_id'),
                    'client_secret' => config('adaptive.canvas.oauth.client_secret'),
                    'redirect_uri' => $this->redirectUri(),
                ]);
        } catch (ConnectionException) {
            throw new CanvasUnavailable('Canvas could not be reached. Try again later.');
        }

        if (in_array($response->status(), [400, 401], true)) {
            throw new CanvasNotConnected('Canvas did not accept the sign-in. Try again.');
        }
        if (! $response->successful()) {
            throw new CanvasUnavailable("Canvas returned an error ({$response->status()}). Try again later.");
        }

        return (array) $response->json();
    }

    private function redirectUri(): string
    {
        return config('adaptive.canvas.oauth.redirect_uri')
            ?: rtrim((string) config('app.url'), '/').'/api/canvas/oauth/callback';
    }
}
