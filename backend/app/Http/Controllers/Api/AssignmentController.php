<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    /**
     * List assignments for a course.
     * Access: enrolled students, course lecturer, admin.
     */
    public function index(Request $request, $courseId)
    {
        $user   = $request->user();
        $course = Course::findOrFail($courseId);

        $this->authorizeCourseAccess($user, $course);

        $assignments = Assignment::where('course_id', $courseId)
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => $assignments]);
    }

    /**
     * Show a single assignment.
     */
    public function show(Request $request, $id)
    {
        $user       = $request->user();
        $assignment = Assignment::with('course')->findOrFail($id);

        $this->authorizeCourseAccess($user, $assignment->course);

        return response()->json(['data' => $assignment]);
    }

    /**
     * Create an assignment for a course (lecturer/admin only).
     */
    public function store(Request $request, $courseId)
    {
        $user   = $request->user();
        $course = Course::findOrFail($courseId);

        if ($user->role === 'lecturer' && $course->lecturer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'rubric'             => ['required', 'array'],
            'allowed_file_types' => ['nullable', 'array'],
            'max_file_size_mb'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'due_date'           => ['nullable', 'date'],
            'topic_ids'          => ['nullable', 'array'],
            'topic_ids.*'        => ['integer'],
            'release_policy'     => ['nullable', 'in:manual,auto'],
        ]);

        $assignment = Assignment::create(array_merge($validated, ['course_id' => $courseId]));

        return response()->json(['data' => $assignment], 201);
    }

    /**
     * Update an assignment.
     */
    public function update(Request $request, $id)
    {
        $user       = $request->user();
        $assignment = Assignment::with('course')->findOrFail($id);

        if ($user->role === 'lecturer' && $assignment->course->lecturer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'title'              => ['sometimes', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'rubric'             => ['sometimes', 'array'],
            'allowed_file_types' => ['nullable', 'array'],
            'max_file_size_mb'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'due_date'           => ['nullable', 'date'],
            'topic_ids'          => ['nullable', 'array'],
            'topic_ids.*'        => ['integer'],
            'release_policy'     => ['nullable', 'in:manual,auto'],
        ]);

        $assignment->update($validated);

        return response()->json(['data' => $assignment->fresh()]);
    }

    /**
     * Delete an assignment.
     */
    public function destroy(Request $request, $id)
    {
        $user       = $request->user();
        $assignment = Assignment::with('course')->findOrFail($id);

        if ($user->role === 'lecturer' && $assignment->course->lecturer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }

    // -------------------------------------------------------------------------

    private function authorizeCourseAccess($user, Course $course): void
    {
        if ($user->role === 'admin') return;

        if ($user->role === 'lecturer' && $course->lecturer_id === $user->id) return;

        if ($user->role === 'student') {
            $enrolled = CourseEnrollment::where('course_id', $course->id)
                ->where('user_id', $user->id)
                ->exists();
            if ($enrolled) return;
        }

        abort(403, 'You do not have access to this course.');
    }
}
