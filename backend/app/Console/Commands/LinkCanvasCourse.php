<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds a real Canvas course to LearnLoop (admin task). Lecturers and students then see it
 * once they connect Canvas, based on their Canvas enrolment.
 *
 *   php artisan learnloop:link-course 12345 --name="Web Technologies" --code=CS341
 *
 * The Canvas course ID is the number in the course's Canvas address: /courses/12345
 */
class LinkCanvasCourse extends Command
{
    protected $signature = 'learnloop:link-course
        {canvas_course_id : The number from the Canvas course address (/courses/12345)}
        {--name= : Course name shown in LearnLoop}
        {--code= : Course code, e.g. CS341}';

    protected $description = 'Link a Canvas course to a new LearnLoop course';

    public function handle(): int
    {
        $canvasId = (string) $this->argument('canvas_course_id');
        $name = trim((string) $this->option('name'));

        if (! ctype_digit($canvasId)) {
            $this->error('The Canvas course ID must be a number, e.g. 12345.');

            return self::FAILURE;
        }
        if ($name === '') {
            $this->error('Give the course a name with --name="…".');

            return self::FAILURE;
        }
        if (CourseLink::where('canvas_course_id', $canvasId)->exists()) {
            $this->error("Canvas course {$canvasId} is already linked.");

            return self::FAILURE;
        }

        $course = DB::transaction(function () use ($canvasId, $name) {
            $course = Course::create(['name' => $name, 'code' => $this->option('code') ?: null]);
            CourseLink::create(['course_id' => $course->id, 'canvas_course_id' => $canvasId]);

            return $course;
        });

        $this->info("Linked Canvas course {$canvasId} as LearnLoop course #{$course->id} ({$course->name}).");
        $this->line('Next: the lecturer connects Canvas, adds topics and maps Canvas assignments to them.');

        return self::SUCCESS;
    }
}
