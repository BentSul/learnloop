<?php

namespace App\Services\Adaptive\Canvas;

use RuntimeException;

/**
 * Canvas sample data read from JSON files (v0.8 §3.1 Tier 3).
 * Used for development, automated tests and a guaranteed demo. One file per course:
 * database/fixtures/canvas/course-{canvas_course_id}.json
 *
 * To simulate a Canvas regrade during a demo, edit a score in the JSON and sync again:
 * LearnLoop records a new version.
 */
final class FixtureCanvasGateway implements CanvasGateway
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function course(string $canvasCourseId, string $asCanvasUserId): ?array
    {
        $data = $this->load($canvasCourseId);
        if ($data === null || $this->courseRole($canvasCourseId, $asCanvasUserId) === null) {
            return null;
        }

        return [
            'id' => (string) $data['course']['id'],
            'name' => $data['course']['name'],
            'course_code' => $data['course']['course_code'],
        ];
    }

    public function courseRole(string $canvasCourseId, string $canvasUserId): ?string
    {
        foreach ($this->load($canvasCourseId)['enrollments'] ?? [] as $enrollment) {
            if ((string) $enrollment['user_id'] === $canvasUserId) {
                return $enrollment['role'];
            }
        }

        return null;
    }

    public function courseStudents(string $canvasCourseId, string $asCanvasUserId): array
    {
        if ($this->courseRole($canvasCourseId, $asCanvasUserId) !== 'teacher') {
            return [];
        }

        return array_values(array_map(
            fn (array $e) => (string) $e['user_id'],
            array_filter($this->load($canvasCourseId)['enrollments'] ?? [], fn (array $e) => $e['role'] === 'student'),
        ));
    }

    public function assignments(string $canvasCourseId, string $asCanvasUserId): array
    {
        if ($this->courseRole($canvasCourseId, $asCanvasUserId) === null) {
            return [];
        }

        return array_map(fn (array $a) => [
            'id' => (string) $a['id'],
            'name' => $a['name'],
            'points_possible' => $a['points_possible'] ?? null,
            'due_at' => $a['due_at'] ?? null,
            'submission_types' => $a['submission_types'] ?? [],
            'rubric' => array_map(fn (array $c) => [
                'id' => (string) $c['id'],
                'description' => $c['description'],
                'points' => $c['points'],
            ], $a['rubric'] ?? []),
        ], $this->load($canvasCourseId)['assignments'] ?? []);
    }

    public function submissionsFor(string $canvasCourseId, string $canvasUserId): array
    {
        if ($this->courseRole($canvasCourseId, $canvasUserId) === null) {
            return [];
        }

        $submissions = array_filter(
            $this->load($canvasCourseId)['submissions'] ?? [],
            fn (array $s) => (string) $s['user_id'] === $canvasUserId,
        );

        return array_values(array_map(fn (array $s) => [
            'assignment_id' => (string) $s['assignment_id'],
            'user_id' => (string) $s['user_id'],
            'score' => $s['score'] ?? null,
            'excused' => (bool) ($s['excused'] ?? false),
            'workflow_state' => $s['workflow_state'] ?? 'graded',
            'submitted_at' => $s['submitted_at'] ?? null,
            'graded_at' => $s['graded_at'] ?? null,
            'posted_at' => $s['posted_at'] ?? null,
            'attempt' => $s['attempt'] ?? 1,
            'rubric_assessment' => $s['rubric_assessment'] ?? [],
        ], $submissions));
    }

    /** @return array<string, mixed>|null */
    private function load(string $canvasCourseId): ?array
    {
        if (array_key_exists($canvasCourseId, $this->cache)) {
            return $this->cache[$canvasCourseId];
        }

        $path = rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR."course-{$canvasCourseId}.json";
        if (! is_file($path)) {
            return $this->cache[$canvasCourseId] = null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException("Canvas fixture {$path} is not valid JSON.");
        }

        return $this->cache[$canvasCourseId] = $data;
    }
}
