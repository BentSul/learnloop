<?php

namespace Tests\Feature;

use App\Models\CanvasConnection;
use App\Models\Course;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasGateway;
use App\Services\Adaptive\Canvas\RoutingCanvasGateway;
use App\Services\Adaptive\CourseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Running LearnLoop on a public host: hybrid Canvas mode, one-time demo data, HTTPS proxy. */
class HostingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://canvas.example.edu';

    public function test_demo_data_loads_once_and_never_over_real_accounts(): void
    {
        $this->artisan('learnloop:seed-demo')->assertSuccessful();
        $this->assertSame(6, User::where('email', 'like', '%learnloop.test')->count());
        $this->assertSame(2, Course::count());

        $this->artisan('learnloop:seed-demo')->expectsOutputToContain('not loaded')->assertSuccessful();
        $this->assertSame(2, Course::count());
    }

    public function test_demo_password_can_be_set_for_a_public_server(): void
    {
        putenv('DEMO_PASSWORD=team-only-secret');
        try {
            $this->artisan('learnloop:seed-demo');
        } finally {
            putenv('DEMO_PASSWORD');
        }

        $admin = User::where('email', 'admin@learnloop.test')->firstOrFail();
        $this->assertTrue(Hash::check('team-only-secret', $admin->password));
        $this->assertFalse(Hash::check('password123', $admin->password));
    }

    public function test_hybrid_mode_serves_demo_and_real_canvas_users_together(): void
    {
        config(['adaptive.canvas.adapter' => 'hybrid', 'adaptive.canvas.base_url' => self::BASE]);
        $this->seed();
        $this->assertInstanceOf(RoutingCanvasGateway::class, app(CanvasGateway::class));

        // A real user connects a token and is a student in real course 777.
        Http::fake([
            self::BASE.'/api/v1/users/self' => Http::response(['id' => 98765]),
            self::BASE.'/api/v1/courses/777/enrollments*' => Http::response([['type' => 'StudentEnrollment']]),
            self::BASE.'/*' => Http::response([], 404),
        ]);
        $real = User::factory()->create();
        Sanctum::actingAs($real);
        $this->postJson('/api/me/canvas', ['access_token' => str_repeat('t', 40)])->assertOk();
        $this->artisan('learnloop:link-course', ['canvas_course_id' => '777', '--name' => 'Real Course'])->assertSuccessful();

        $access = app(CourseAccess::class);
        $realCourse = Course::where('name', 'Real Course')->firstOrFail();
        $demoCourse = Course::where('code', 'WT101')->firstOrFail();
        $jamie = User::where('email', 'jamie.lee@student.learnloop.test')->firstOrFail();

        $this->assertTrue($access->isStudent($real, $realCourse), 'real user reads real Canvas');
        $this->assertFalse($access->isStudent($real, $demoCourse), 'real user is not in the demo course');
        $this->assertTrue($access->isStudent($jamie, $demoCourse), 'demo user still reads the sample data');
        $this->assertFalse($access->isStudent($jamie, $realCourse));
        // Demo users never cause a request to Canvas: every request carries the real user's token.
        Http::assertNotSent(fn ($r) => ! $r->hasHeader('Authorization', 'Bearer '.str_repeat('t', 40)));

        Sanctum::actingAs($jamie);
        $this->getJson("/api/me/courses/{$demoCourse->id}/topics")->assertOk();
        $this->assertSame('fixture', CanvasConnection::where('user_id', $jamie->id)->value('adapter'));
    }

    public function test_https_proxy_headers_are_trusted(): void
    {
        $this->get('/up', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '10.0.0.1'])->assertOk();

        $this->get('/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'learnloop.example.app'])->assertOk();
        $this->assertSame('https://learnloop.example.app/x', url('/x'));
    }

    public function test_make_admin_command(): void
    {
        $user = User::factory()->create(['email' => 'lead@example.com']);

        $this->artisan('learnloop:make-admin', ['emails' => ['lead@example.com', 'missing@example.com']])
            ->expectsOutputToContain('lead@example.com is now an admin.')
            ->expectsOutputToContain('No account for missing@example.com yet')
            ->assertSuccessful();
        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_start_script_links_courses_and_admins_from_variables(): void
    {
        $script = file_get_contents(base_path('start-container.sh'));
        $this->assertStringContainsString('php artisan migrate --force', $script);
        $this->assertStringContainsString('learnloop:seed-demo', $script);
        $this->assertStringContainsString('learnloop:link-course', $script);
        $this->assertStringContainsString('learnloop:make-admin', $script);
        $this->assertStringContainsString('exec docker-php-entrypoint', $script);
    }

    public function test_expected_errors_are_not_logged_but_canvas_outages_are(): void
    {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new \App\Services\Adaptive\Quiz\Exceptions\AttemptNotFound()));
        $this->assertFalse($handler->shouldReport(\App\Services\Adaptive\Lecturer\LecturerException::notFound()));
        $this->assertTrue($handler->shouldReport(new \App\Services\Adaptive\Canvas\CanvasUnavailable('Canvas down')));
        $this->assertTrue($handler->shouldReport(new \RuntimeException('real bug')));
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        }
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertStatus(429);
    }
}
