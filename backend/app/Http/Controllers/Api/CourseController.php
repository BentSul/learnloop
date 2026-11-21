<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * List courses based on the authenticated user's role.
     * - Students: courses they are enrolled in
     * - Lecturers: courses they own
     * - Admins: all courses
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Course::with(['lecturer:id,name,email', 'modules']);

        if ($user->role === 'student') {
            $query->whereHas('enrollments', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === 'lecturer') {
            $query->where('lecturer_id', $user->id);
        }
        // admins get all

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Show a single course with its modules and lessons.
     */
    public function show(Request $request, $id)
    {
        $user   = $request->user();
        $course = Course::with(['lecturer:id,name,email', 'modules.lessons'])->findOrFail($id);

        $this->authorizeCourseAccess($user, $course);

        return response()->json(['data' => $course]);
    }

    /**
     * Create a new course (lecturer/admin only).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'code'         => ['required', 'string', 'max:50', 'unique:courses,code'],
            'is_published' => ['boolean'],
        ]);

        $user = $request->user();
        $lecturerId = ($user->role === 'admin' && $request->has('lecturer_id'))
            ? $request->integer('lecturer_id')
            : $user->id;

        $course = Course::create(array_merge($validated, ['lecturer_id' => $lecturerId]));

        return response()->json(['data' => $course->load('lecturer:id,name,email')], 201);
    }

    /**
     * Update a course (lecturer can only update own course; admin can update any).
     */
    public function update(Request $request, $id)
    {
        $user   = $request->user();
        $course = Course::findOrFail($id);

        if ($user->role === 'lecturer' && $course->lecturer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'title'        => ['sometimes', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'code'         => ['sometimes', 'string', 'max:50', "unique:courses,code,{$id}"],
            'is_published' => ['boolean'],
        ]);

        $course->update($validated);

        return response()->json(['data' => $course->fresh('lecturer:id,name,email')]);
    }

    /**
     * Enroll the authenticated student in a course.
     */
    public function enroll(Request $request, $id)
    {
        $user   = $request->user();
        $course = Course::findOrFail($id);

        if ($user->role !== 'student') {
            return response()->json(['message' => 'Only students can enroll in courses.'], 403);
        }

        if (!$course->is_published) {
            return response()->json(['message' => 'This course is not available for enrollment.'], 403);
        }

        $existing = CourseEnrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already enrolled in this course.'], 409);
        }

        $enrollment = CourseEnrollment::create([
            'course_id'   => $course->id,
            'user_id'     => $user->id,
            'enrolled_at' => now(),
        ]);

        return response()->json(['data' => $enrollment], 201);
    }

    /**
     * Unenroll the authenticated student from a course.
     */
    public function unenroll(Request $request, $id)
    {
        $user   = $request->user();
        $course = Course::findOrFail($id);

        $deleted = CourseEnrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'You are not enrolled in this course.'], 404);
        }

        return response()->json(['message' => 'Unenrolled successfully.']);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function authorizeCourseAccess($user, Course $course): void
    {
        if ($user->role === 'admin') {
            return;
        }

        if ($user->role === 'lecturer' && $course->lecturer_id === $user->id) {
            return;
        }

        if ($user->role === 'student') {
            $enrolled = CourseEnrollment::where('course_id', $course->id)
                ->where('user_id', $user->id)
                ->exists();
            if ($enrolled) {
                return;
            }
        }

        abort(403, 'You do not have access to this course.');
    }
}
