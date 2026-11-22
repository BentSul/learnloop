<?php

namespace App\Services\Adaptive;

use App\Models\CanvasConnection;
use App\Models\Course;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasGateway;
use Illuminate\Support\Collection;

/**
 * Who may see which course, taken from Canvas enrolments (v0.8 D12).
 * A Canvas 'teacher' in the linked course is that course's lecturer; a 'student' is a learner.
 * Admins see everything. Users without an active Canvas connection see nothing.
 */
final class CourseAccess
{
    public function __construct(private readonly CanvasGateway $gateway)
    {
    }

    public function isStudent(User $user, Course $course): bool
    {
        return $this->role($user, $course) === 'student';
    }

    public function isLecturer(User $user, Course $course): bool
    {
        return $user->role === 'admin' || $this->role($user, $course) === 'teacher';
    }

    /** @return Collection<int, Course> courses the user studies or teaches */
    public function coursesFor(User $user): Collection
    {
        return Course::with('canvasLink')->orderBy('name')->get()
            ->filter(fn (Course $course) => $user->role === 'admin' || $this->role($user, $course) !== null)
            ->values();
    }

    private function role(User $user, Course $course): ?string
    {
        $connection = CanvasConnection::where('user_id', $user->id)->whereNull('revoked_at')->first();
        $canvasCourseId = $course->canvasLink?->canvas_course_id;

        if ($connection === null || $canvasCourseId === null) {
            return null;
        }

        return $this->gateway->courseRole($canvasCourseId, $connection->canvas_user_id);
    }
}
