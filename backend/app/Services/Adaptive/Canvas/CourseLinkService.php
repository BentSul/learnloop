<?php

namespace App\Services\Adaptive\Canvas;

use App\Models\CanvasConnection;
use App\Models\Course;
use App\Models\CourseLink;
use App\Models\User;
use App\Services\Adaptive\Lecturer\LecturerException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Lecturers add their own Canvas courses to LearnLoop from the website (instead of an admin
 * running learnloop:link-course). A lecturer can add only courses they teach in Canvas;
 * an admin can add any course by its Canvas ID.
 */
class CourseLinkService
{
    public function __construct(private readonly CanvasGateway $gateway)
    {
    }

    /**
     * Canvas courses the user teaches, each marked with its LearnLoop course if already added.
     *
     * @return list<array{canvas_course_id: string, name: string, course_code: string, learnloop_course_id: int|null}>
     */
    public function teachingCourses(User $user): array
    {
        $canvasUserId = $this->connection($user)->canvas_user_id;
        $courses = $this->gateway->teachingCourses($canvasUserId);
        $linked = CourseLink::whereIn('canvas_course_id', array_column($courses, 'id'))
            ->pluck('course_id', 'canvas_course_id');

        return array_map(fn (array $c) => [
            'canvas_course_id' => $c['id'],
            'name' => $c['name'],
            'course_code' => $c['course_code'],
            'learnloop_course_id' => $linked[$c['id']] ?? null,
        ], $courses);
    }

    /**
     * @param  array{name?: string|null, code?: string|null}  $override  admin only, when Canvas details cannot be read
     * @return array{id: int, name: string, code: string|null, canvas_course_id: string}
     */
    public function link(User $user, string $canvasCourseId, array $override = []): array
    {
        $canvasCourseId = trim($canvasCourseId);
        if (! ctype_digit($canvasCourseId)) {
            throw LecturerException::invalid('The Canvas course ID is the number in the course address, e.g. 12345 in /courses/12345.');
        }
        if (CourseLink::where('canvas_course_id', $canvasCourseId)->exists()) {
            throw LecturerException::conflict('This Canvas course is already in LearnLoop.');
        }

        $isAdmin = $user->role === 'admin';
        $connection = CanvasConnection::where('user_id', $user->id)->whereNull('revoked_at')->first();
        $details = null;

        if ($connection !== null) {
            $role = $this->gateway->courseRole($canvasCourseId, $connection->canvas_user_id);
            if ($role !== 'teacher' && ! $isAdmin) {
                throw LecturerException::invalid('You can only add Canvas courses you teach.');
            }
            $details = $role === null ? null : $this->gateway->course($canvasCourseId, $connection->canvas_user_id);
        } elseif (! $isAdmin) {
            throw new CanvasNotConnected('Connect your Canvas account first.');
        }

        $name = trim((string) ($details['name'] ?? ($isAdmin ? ($override['name'] ?? '') : '')));
        $code = trim((string) ($details['course_code'] ?? ($isAdmin ? ($override['code'] ?? '') : '')));
        if ($name === '') {
            throw LecturerException::invalid('LearnLoop could not read this course from Canvas. Give it a name.');
        }

        $code = self::uniqueCode($code, $canvasCourseId);

        try {
            $course = DB::transaction(function () use ($name, $code, $canvasCourseId) {
                $course = Course::create(['name' => mb_substr($name, 0, 255), 'code' => $code]);
                CourseLink::create(['course_id' => $course->id, 'canvas_course_id' => $canvasCourseId]);

                return $course;
            });
        } catch (UniqueConstraintViolationException) {
            throw LecturerException::conflict('This Canvas course is already in LearnLoop.');
        }

        return ['id' => $course->id, 'name' => $course->name, 'code' => $course->code, 'canvas_course_id' => $canvasCourseId];
    }

    /** Course codes are unique in LearnLoop; when one is taken (e.g. by a demo course), add the Canvas ID. */
    public static function uniqueCode(?string $code, string $canvasCourseId): ?string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        $code = mb_substr($code, 0, 50);
        if (! Course::where('code', $code)->exists()) {
            return $code;
        }

        return mb_substr($code, 0, 49 - strlen($canvasCourseId)).'-'.$canvasCourseId;
    }

    private function connection(User $user): CanvasConnection
    {
        return CanvasConnection::where('user_id', $user->id)->whereNull('revoked_at')->first()
            ?? throw new CanvasNotConnected('Connect your Canvas account first.');
    }
}
