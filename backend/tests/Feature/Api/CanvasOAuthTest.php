<?php

namespace Tests\Feature\Api;

use App\Models\CanvasConnection;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** "Sign in with Canvas" / "Connect with Canvas" through a developer key (OAuth2). */
class CanvasOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://canvas.example.edu';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://learnloop.example.app',
            'adaptive.canvas.adapter' => 'hybrid',
            'adaptive.canvas.base_url' => self::BASE,
            'adaptive.canvas.oauth.client_id' => '1000000042',
            'adaptive.canvas.oauth.client_secret' => 'dev-key-secret',
        ]);
    }

    private function fakeCanvas(int $canvasUserId = 4242, array $profile = ['primary_email' => 'kofi@ashesi.edu.gh']): void
    {
        Http::fake([
            self::BASE.'/login/oauth2/token' => Http::response([
                'access_token' => 'access-1', 'token_type' => 'Bearer', 'refresh_token' => 'refresh-1',
                'expires_in' => 3600, 'user' => ['id' => $canvasUserId, 'name' => 'Kofi Mensah'],
            ]),
            self::BASE.'/api/v1/users/self/profile' => Http::response($profile),
        ]);
    }

    private function stateFrom(string $authorizeUrl): string
    {
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $q);

        return $q['state'];
    }

    public function test_it_is_off_until_a_developer_key_is_configured(): void
    {
        config(['adaptive.canvas.oauth.client_id' => null]);
        $this->getJson('/api/auth/canvas')->assertOk()->assertExactJson(['enabled' => false]);
        $this->postJson('/api/auth/canvas')->assertStatus(409);
    }

    public function test_sign_in_with_canvas_creates_an_account_and_signs_in(): void
    {
        $this->getJson('/api/auth/canvas')->assertExactJson(['enabled' => true]);
        $url = $this->postJson('/api/auth/canvas')->assertOk()->json('authorize_url');

        $this->assertStringStartsWith(self::BASE.'/login/oauth2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('1000000042', $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('https://learnloop.example.app/api/canvas/oauth/callback', $q['redirect_uri']);
        $this->assertArrayNotHasKey('client_secret', $q);

        $this->fakeCanvas();
        $redirect = $this->get('/api/canvas/oauth/callback?code=abc&state='.$q['state'])->assertRedirect()->headers->get('Location');

        $this->assertStringContainsString('/auth/canvas#token=', $redirect);
        $apiToken = rawurldecode(explode('#token=', $redirect)[1]);
        $this->withToken($apiToken)->getJson('/api/auth/me')->assertOk()->assertJsonFragment(['email' => 'kofi@ashesi.edu.gh']);

        $user = User::where('email', 'kofi@ashesi.edu.gh')->sole();
        $this->assertSame('student', $user->role);
        $connection = CanvasConnection::where('user_id', $user->id)->sole();
        $this->assertSame(['4242', 'token', 'access-1', 'refresh-1'], [$connection->canvas_user_id, $connection->adapter, $connection->access_token, $connection->refresh_token]);
        $this->assertNotSame('refresh-1', DB::table('canvas_connections')->value('refresh_token'), 'stored encrypted');

        Http::assertSent(fn ($r) => $r->url() === self::BASE.'/login/oauth2/token'
            && $r['grant_type'] === 'authorization_code' && $r['code'] === 'abc' && $r['client_secret'] === 'dev-key-secret');

        // The state is single use.
        $this->get('/api/canvas/oauth/callback?code=abc&state='.$q['state'])->assertRedirect('/login?canvas_error=expired');
    }

    public function test_signing_in_again_reuses_the_account(): void
    {
        $this->fakeCanvas();
        $first = $this->postJson('/api/auth/canvas')->json('authorize_url');
        $this->get('/api/canvas/oauth/callback?code=a&state='.$this->stateFrom($first));
        $second = $this->postJson('/api/auth/canvas')->json('authorize_url');
        $this->get('/api/canvas/oauth/callback?code=b&state='.$this->stateFrom($second))->assertRedirectContains('/auth/canvas#token=');

        $this->assertSame(1, User::count());
    }

    public function test_never_attaches_canvas_to_an_existing_password_account_by_email(): void
    {
        $existing = User::factory()->create(['email' => 'kofi@ashesi.edu.gh']);
        $this->fakeCanvas();
        $url = $this->postJson('/api/auth/canvas')->json('authorize_url');

        $this->get('/api/canvas/oauth/callback?code=abc&state='.$this->stateFrom($url))->assertRedirect('/login?canvas_error=email_in_use');
        $this->assertSame(0, CanvasConnection::count());
        $this->assertSame(0, $existing->tokens()->count());
    }

    public function test_hidden_canvas_email_gets_a_placeholder(): void
    {
        $this->fakeCanvas(4242, []);
        $url = $this->postJson('/api/auth/canvas')->json('authorize_url');
        $this->get('/api/canvas/oauth/callback?code=abc&state='.$this->stateFrom($url));

        $this->assertTrue(User::where('email', 'canvas-4242@users.canvas.invalid')->exists());
    }

    public function test_connect_with_canvas_while_signed_in(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/me/canvas')->assertJson(['connected' => false, 'oauth_available' => true]);

        $url = $this->postJson('/api/me/canvas/oauth')->assertOk()->json('authorize_url');
        $this->fakeCanvas();
        $this->get('/api/canvas/oauth/callback?code=abc&state='.$this->stateFrom($url))->assertRedirect('/canvas?canvas_connected=1');

        $this->getJson('/api/me/canvas')->assertJson(['connected' => true, 'canvas_user_id' => '4242']);
        $this->assertSame(1, User::count(), 'no second account');
    }

    public function test_connect_refuses_a_canvas_account_linked_elsewhere(): void
    {
        CanvasConnection::create(['user_id' => User::factory()->create()->id, 'canvas_user_id' => '4242', 'adapter' => 'token',
            'access_token' => 'x', 'connected_at' => now()]);
        Sanctum::actingAs(User::factory()->create());
        $url = $this->postJson('/api/me/canvas/oauth')->json('authorize_url');
        $this->fakeCanvas();

        $this->get('/api/canvas/oauth/callback?code=abc&state='.$this->stateFrom($url))->assertRedirect('/canvas?canvas_error=already_linked');
    }

    public function test_cancelled_forged_and_failed_callbacks(): void
    {
        $url = $this->postJson('/api/auth/canvas')->json('authorize_url');
        $this->get('/api/canvas/oauth/callback?error=access_denied&state='.$this->stateFrom($url))->assertRedirect('/login?canvas_error=denied');
        $this->get('/api/canvas/oauth/callback?code=abc&state=forged')->assertRedirect('/login?canvas_error=expired');

        Http::fake([self::BASE.'/login/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $url = $this->postJson('/api/auth/canvas')->json('authorize_url');
        $this->get('/api/canvas/oauth/callback?code=bad&state='.$this->stateFrom($url))->assertRedirect('/login?canvas_error=failed');
        $this->assertSame(0, User::count());
    }

    public function test_expired_access_tokens_are_renewed_and_revoked_ones_disconnect(): void
    {
        $user = User::factory()->create();
        CanvasConnection::create(['user_id' => $user->id, 'canvas_user_id' => '4242', 'adapter' => 'token',
            'access_token' => 'old-access', 'refresh_token' => 'refresh-1', 'expires_at' => now()->subMinute(), 'connected_at' => now()]);

        Http::fake([
            self::BASE.'/login/oauth2/token' => Http::sequence()
                ->push(['access_token' => 'new-access', 'expires_in' => 3600, 'user' => ['id' => 4242]])
                ->push(['error' => 'invalid_grant'], 400),
            self::BASE.'/api/v1/courses/900/assignments*' => Http::response([]),
        ]);
        app(CanvasGateway::class)->assignments('900', '4242');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/assignments') && $r->hasHeader('Authorization', 'Bearer new-access'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/login/oauth2/token') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-1');
        $connection = CanvasConnection::sole();
        $this->assertSame('new-access', $connection->access_token);
        $this->assertSame('refresh-1', $connection->refresh_token, 'Canvas keeps the same refresh token');
        $this->assertTrue($connection->expires_at->isFuture());

        // The user removed LearnLoop from their Canvas settings: renewal fails, connection is closed.
        $connection->update(['expires_at' => now()->subMinute()]);
        $this->assertNull(app(CanvasGateway::class)->courseRole('900', '4242'));
        $this->assertNotNull($connection->fresh()->revoked_at);
    }
}
