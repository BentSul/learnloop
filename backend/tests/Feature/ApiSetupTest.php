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

    public function test_register_returns_token_and_creates_student(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test Student',
            'email' => 'student@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonStructure(['token', 'token_type', 'user']);
    }

    public function test_register_ignores_requested_role(): void
    {
        foreach (['lecturer', 'admin'] as $role) {
            $this->postJson('/api/auth/register', [
                'name' => 'Sneaky '.$role,
                'email' => $role.'@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => $role,
            ])->assertCreated()
                ->assertJsonPath('user.role', 'student');

            $this->assertSame('student', User::where('email', $role.'@example.test')->value('role'));
        }
    }

    public function test_user_seeder_creates_lecturer_and_admin(): void
    {
        $this->seed();

        $this->assertSame('admin', User::where('email', 'admin@learnloop.test')->value('role'));
        $this->assertSame('lecturer', User::where('email', 'dr.sarah.chen@learnloop.test')->value('role'));
        $this->assertSame('student', User::where('email', 'alex.morgan@student.learnloop.test')->value('role'));
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
