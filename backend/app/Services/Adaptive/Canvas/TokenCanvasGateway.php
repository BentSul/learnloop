<?php

namespace App\Services\Adaptive\Canvas;

use App\Models\CanvasConnection;
use Illuminate\Support\Facades\Cache;

/**
 * The real Canvas adapter (v0.8 §3.1 Tier 2): every call uses the access token of the
 * Canvas user it is made for, so a learner only ever reads their own data and a lecturer
 * only reads courses they teach. Output uses the same shapes as FixtureCanvasGateway.
 */
final class TokenCanvasGateway implements CanvasGateway
{
    /** @var array<string, ?string> roles looked up during this request */
    private array $roles = [];

    public function __construct(private readonly CanvasClient $client)
    {
    }

    public function course(string $canvasCourseId, string $asCanvasUserId): ?array
    {
        $course = $this->client->getObject($this->token($asCanvasUserId), '/api/v1/courses/'.rawurlencode($canvasCourseId));

        return $course === null ? null : [
            'id' => (string) $course['id'],
            'name' => (string) ($course['name'] ?? ''),
            'course_code' => (string) ($course['course_code'] ?? ''),
        ];
    }

    public function courseRole(string $canvasCourseId, string $canvasUserId): ?string
    {
        $key = "{$canvasCourseId}:{$canvasUserId}";
        if (array_key_exists($key, $this->roles)) {
            return $this->roles[$key];
        }

        $token = $this->tokenOrNull($canvasUserId);
        if ($token === null) {
            return $this->roles[$key] = null;
        }

        // The token is part of the cache key, so reconnecting with a new token looks the role up again.
        $cacheKey = 'canvas-role:'.hash('sha256', $key.'|'.$token);
        $minutes = (int) config('adaptive.canvas.role_cache_minutes', 10);

        // Cache::remember skips null, so store '' for "not enrolled". If Canvas fails or the token has
        // expired, treat the user as not enrolled for this request only (nothing is cached), so one
        // Canvas outage does not break every LearnLoop page.
        try {
            $role = Cache::remember($cacheKey, now()->addMinutes($minutes), fn () => $this->lookUpRole($canvasCourseId, $token) ?? '');
        } catch (CanvasUnavailable|CanvasNotConnected) {
            return $this->roles[$key] = null;
        }

        return $this->roles[$key] = ($role === '' ? null : $role);
    }

    public function courseStudents(string $canvasCourseId, string $asCanvasUserId): array
    {
        if ($this->courseRole($canvasCourseId, $asCanvasUserId) !== 'teacher') {
            return [];
        }

        $users = $this->client->getList($this->token($asCanvasUserId),
            '/api/v1/courses/'.rawurlencode($canvasCourseId).'/users', ['enrollment_type' => ['student']]);

        return array_values(array_map(fn (array $u) => (string) $u['id'], $users));
    }

    public function assignments(string $canvasCourseId, string $asCanvasUserId): array
    {
        $assignments = $this->client->getList($this->token($asCanvasUserId),
            '/api/v1/courses/'.rawurlencode($canvasCourseId).'/assignments');

        return array_map(fn (array $a) => [
            'id' => (string) $a['id'],
            'name' => (string) ($a['name'] ?? ''),
            'points_possible' => $a['points_possible'] ?? null,
            'due_at' => $a['due_at'] ?? null,
            'submission_types' => array_values((array) ($a['submission_types'] ?? [])),
            'rubric' => array_map(fn ($c) => [
                'id' => (string) $c['id'],
                'description' => (string) ($c['description'] ?? ''),
                'points' => $c['points'] ?? 0,
            ], array_values((array) ($a['rubric'] ?? []))),
        ], $assignments);
    }

    public function submissionsFor(string $canvasCourseId, string $canvasUserId): array
    {
        $submissions = $this->client->getList($this->token($canvasUserId),
            '/api/v1/courses/'.rawurlencode($canvasCourseId).'/students/submissions',
            ['student_ids' => [$canvasUserId], 'include' => ['rubric_assessment']]);

        return array_values(array_map(fn (array $s) => [
            'assignment_id' => (string) $s['assignment_id'],
            'user_id' => (string) $s['user_id'],
            'score' => $s['score'] ?? null,
            'excused' => (bool) ($s['excused'] ?? false),
            'workflow_state' => (string) ($s['workflow_state'] ?? 'unsubmitted'),
            'submitted_at' => $s['submitted_at'] ?? null,
            'graded_at' => $s['graded_at'] ?? null,
            'posted_at' => $s['posted_at'] ?? null,
            'attempt' => $s['attempt'] ?? null,
            'rubric_assessment' => array_map(
                fn ($r) => ['points' => is_array($r) ? ($r['points'] ?? null) : null],
                (array) ($s['rubric_assessment'] ?? []),
            ),
        ], array_filter($submissions, fn (array $s) => (string) ($s['user_id'] ?? '') === $canvasUserId)));
    }

    private function lookUpRole(string $canvasCourseId, string $token): ?string
    {
        $enrollments = $this->client->getList($token,
            '/api/v1/courses/'.rawurlencode($canvasCourseId).'/enrollments',
            ['user_id' => 'self', 'state' => ['active']]);

        $types = array_column($enrollments, 'type');
        $teacherTypes = (array) config('adaptive.canvas.teacher_enrollment_types', ['TeacherEnrollment']);

        return match (true) {
            array_intersect($types, $teacherTypes) !== [] => 'teacher',
            in_array('StudentEnrollment', $types, true) => 'student',
            default => null,
        };
    }

    private function tokenOrNull(string $canvasUserId): ?string
    {
        $connection = CanvasConnection::where('canvas_user_id', $canvasUserId)
            ->where('adapter', 'token')->whereNull('revoked_at')->first();

        return $connection?->access_token ?: null;
    }

    private function token(string $canvasUserId): string
    {
        return $this->tokenOrNull($canvasUserId) ?? throw new CanvasNotConnected('Connect your Canvas account first.');
    }
}
