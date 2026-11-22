<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Setup smoke tests: Sanctum tokens, users.role, and the `role` middleware alias.
 */
class ApiSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_token_and_role(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test Lecturer',
            'email' => 'lecturer@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'lecturer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'lecturer')
            ->assertJsonStructure(['token', 'token_type', 'user']);
    }

    public function test_register_rejects_admin_role(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertUnprocessable();
    }

    public function test_role_defaults_to_student(): void
    {
        $user = User::factory()->create();

        $this->assertSame('student', $user->fresh()->role);
    }

    public function test_me_requires_token_and_works_with_one(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_role_middleware_alias_allows_and_forbids(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'role:lecturer,admin'])
            ->get('/api/_test/lecturer-only', fn () => ['ok' => true]);

        $student = User::factory()->create(['role' => 'student']);
        $lecturer = User::factory()->create(['role' => 'lecturer']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/_test/lecturer-only')->assertForbidden();

        $this->actingAs($lecturer, 'sanctum')
            ->getJson('/api/_test/lecturer-only')->assertOk()->assertJson(['ok' => true]);
    }
}
