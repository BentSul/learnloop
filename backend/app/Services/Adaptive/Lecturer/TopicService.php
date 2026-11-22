<?php

namespace App\Services\Adaptive\Lecturer;

use App\Models\AttemptItem;
use App\Models\CanvasItemMapping;
use App\Models\Course;
use App\Models\ExternalResultItem;
use App\Models\MarkVersion;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;
use Illuminate\Support\Facades\DB;

/**
 * Lecturers create and maintain the topics of their course. A topic with learner evidence is
 * archived, never deleted, so results and history stay intact (spec §3.1). A topic without
 * any evidence (e.g. created by mistake) can be deleted.
 */
class TopicService
{
    public function __construct(private readonly CourseAccess $access)
    {
    }

    /** @param array<string, mixed> $data {name, description?, learning_outcomes?: list<string>} */
    public function create(User $lecturer, Course $course, array $data): Topic
    {
        $this->assertLecturer($lecturer, $course);
        $clean = $this->validate($data, $course, null);

        return Topic::create($clean + ['course_id' => $course->id]);
    }

    /** @param array<string, mixed> $data any of {name, description, learning_outcomes, status} */
    public function update(User $lecturer, Topic $topic, array $data): Topic
    {
        $this->assertLecturer($lecturer, $topic->course);
        $clean = $this->validate($data + ['name' => $topic->name], $topic->course, $topic);

        if (array_key_exists('status', $data)) {
            if (! in_array($data['status'], ['active', 'archived'], true)) {
                throw LecturerException::invalid('The given data was invalid.', ['status must be active or archived.']);
            }
            $clean['status'] = $data['status'];
        }

        // Archiving hides the topic from learners; the next recommendation reconcile marks its
        // open recommendations obsolete with an event (§10.7).
        $topic->update($clean);

        return $topic->refresh();
    }

    /**
     * Deletes a topic that has no learner evidence, together with its unused questions and
     * Canvas mappings. A topic with evidence must be archived instead (409).
     */
    public function delete(User $lecturer, Topic $topic): void
    {
        $this->assertLecturer($lecturer, $topic->course);

        $hasEvidence = AttemptItem::where('topic_id_snapshot', $topic->id)->exists()
            || MarkVersion::where('topic_id', $topic->id)->exists()
            || ExternalResultItem::where('topic_id', $topic->id)->exists();
        if ($hasEvidence) {
            throw LecturerException::conflict('Students already have results for this topic, so it can only be archived. Archiving hides it and keeps their history.');
        }

        DB::transaction(function () use ($lecturer, $topic) {
            $questionIds = Question::where('topic_id', $topic->id)->pluck('id');
            DB::table('assessment_items')->whereIn('question_id', $questionIds)->delete();
            Question::whereIn('id', $questionIds)->delete();

            // Log removed mappings like any other unmapping, so learners' next sync notices.
            foreach (CanvasItemMapping::where('topic_id', $topic->id)->get() as $mapping) {
                DB::table('mapping_changes')->insert([
                    'course_id' => $mapping->course_id,
                    'canvas_assignment_id' => $mapping->canvas_assignment_id,
                    'canvas_criterion_id' => $mapping->canvas_criterion_id,
                    'old_topic_id' => null, // the topic row is about to be deleted
                    'new_topic_id' => null,
                    'old_difficulty' => $mapping->difficulty,
                    'new_difficulty' => null,
                    'changed_by' => $lecturer->id,
                    'created_at' => now(),
                ]);
                $mapping->delete();
            }

            $topic->delete(); // goals and recommendations for the topic are removed with it
        });
    }

    /** @return array<string, mixed> */
    private function validate(array $data, Course $course, ?Topic $existing): array
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $errors[] = 'name is required (at most 255 characters).';
        }
        $duplicate = Topic::where('course_id', $course->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
        if ($name !== '' && $duplicate) {
            $errors[] = 'This course already has a topic with that name.';
        }
        if ($errors !== []) {
            throw LecturerException::invalid('The given data was invalid.', $errors);
        }

        $clean = ['name' => $name];
        if (array_key_exists('description', $data)) {
            $clean['description'] = $data['description'] === null ? null : (string) $data['description'];
        }
        if (array_key_exists('learning_outcomes', $data)) {
            $outcomes = $data['learning_outcomes'];
            if ($outcomes !== null && (! is_array($outcomes) || array_filter($outcomes, fn ($o) => ! is_string($o)) !== [])) {
                throw LecturerException::invalid('The given data was invalid.', ['learning_outcomes must be a list of text items.']);
            }
            $clean['learning_outcomes'] = $outcomes === null ? null : array_values($outcomes);
        }

        return $clean;
    }

    private function assertLecturer(User $lecturer, Course $course): void
    {
        if (! $this->access->isLecturer($lecturer, $course)) {
            throw LecturerException::notFound();
        }
    }
}
