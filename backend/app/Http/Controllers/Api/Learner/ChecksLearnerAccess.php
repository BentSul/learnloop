<?php

namespace App\Http\Controllers\Api\Learner;

use App\Models\Course;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;

/**
 * Learner routes only work for courses where the user is a Canvas student (v0.8 D12).
 * Anything else is reported as 404 so course existence is not revealed.
 */
trait ChecksLearnerAccess
{
    private function assertStudent(User $user, Course $course): void
    {
        abort_unless(app(CourseAccess::class)->isStudent($user, $course), 404, 'Not found.');
    }

    private function assertStudentTopic(User $user, Topic $topic): void
    {
        abort_unless($topic->status === 'active', 404, 'Not found.');
        $this->assertStudent($user, $topic->course);
    }
}
