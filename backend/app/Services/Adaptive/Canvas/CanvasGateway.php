<?php

namespace App\Services\Adaptive\Canvas;

/**
 * Everything LearnLoop reads from Canvas (v0.8 §3.1–§3.2).
 *
 * Implementations: FixtureCanvasGateway (sample data, tests, demo) now;
 * token and OAuth adapters later (Antigravity). Arrays use Canvas REST API field names,
 * so a real adapter can pass Canvas JSON through with minimal mapping.
 *
 * Every method takes the Canvas user whose access is being used: a learner's token only
 * sees that learner's own data (v0.8 §3.1).
 */
interface CanvasGateway
{
    /**
     * @return array{id: string, name: string, course_code: string}|null
     */
    public function course(string $canvasCourseId, string $asCanvasUserId): ?array;

    /**
     * Role of a user in a Canvas course: 'teacher', 'student' or null (not enrolled).
     * Replaces course-staff and enrolment data from Role 1 (v0.8 D12).
     */
    public function courseRole(string $canvasCourseId, string $canvasUserId): ?string;

    /**
     * Canvas user IDs of the students in a course, as seen by a teacher of that course.
     *
     * @return list<string>
     */
    public function courseStudents(string $canvasCourseId, string $asCanvasUserId): array;

    /**
     * Assignments (including quizzes) with their rubrics.
     *
     * @return list<array{
     *     id: string, name: string, points_possible: float|int|null, due_at: ?string,
     *     submission_types: list<string>,
     *     rubric: list<array{id: string, description: string, points: float|int}>
     * }>
     */
    public function assignments(string $canvasCourseId, string $asCanvasUserId): array;

    /**
     * One learner's current submission per assignment.
     *
     * @return list<array{
     *     assignment_id: string, user_id: string, score: float|int|null, excused: bool,
     *     workflow_state: string, submitted_at: ?string, graded_at: ?string, posted_at: ?string,
     *     attempt: ?int, rubric_assessment: array<string, array{points: float|int|null}>
     * }>
     */
    public function submissionsFor(string $canvasCourseId, string $canvasUserId): array;
}
