<?php

namespace App\Services\Adaptive\Lecturer;

use App\Models\CanvasConnection;
use App\Models\CanvasItemMapping;
use App\Models\Course;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasGateway;
use App\Services\Adaptive\CourseAccess;
use Illuminate\Support\Facades\DB;

/**
 * The lecturer's Canvas mapping screen (v0.8 §3.3, D13).
 *
 * Each Canvas item is mapped either as a whole (one topic) or per rubric criterion (one topic
 * each), never both. Unmapped items produce no evidence. Every change is logged in
 * mapping_changes; learners' evidence is re-derived on their next sync, which happens
 * automatically because CanvasSyncService::needsSync() sees the change.
 */
final class CanvasMappingService
{
    private const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public function __construct(
        private readonly CanvasGateway $gateway,
        private readonly CourseAccess $access,
    ) {
    }

    /** @return list<array<string, mixed>> Canvas items with their current mapping */
    public function items(User $lecturer, Course $course): array
    {
        [$canvasCourseId, $canvasUserId] = $this->canvasContext($lecturer, $course);
        $mappings = CanvasItemMapping::where('course_id', $course->id)->get()
            ->keyBy(fn ($m) => $m->canvas_assignment_id.'|'.$m->canvas_criterion_id);

        $present = fn (?CanvasItemMapping $m) => $m === null ? null : ['topic_id' => $m->topic_id, 'difficulty' => $m->difficulty];

        return array_map(fn (array $a) => [
            'canvas_assignment_id' => $a['id'],
            'name' => $a['name'],
            'points_possible' => $a['points_possible'],
            'submission_types' => $a['submission_types'],
            'mapping' => $present($mappings->get($a['id'].'|')),
            'criteria' => array_map(fn (array $c) => [
                'canvas_criterion_id' => $c['id'],
                'description' => $c['description'],
                'points' => $c['points'],
                'mapping' => $present($mappings->get($a['id'].'|'.$c['id'])),
            ], $a['rubric']),
        ], $this->gateway->assignments($canvasCourseId, $canvasUserId));
    }

    /**
     * Creates, updates or removes mappings. Each entry:
     *   {"canvas_assignment_id": "2001", "canvas_criterion_id": "" | "crit_html",
     *    "topic_id": 5 | null (null removes), "difficulty": "easy|medium|hard" (default medium)}
     *
     * All entries are validated first; nothing is saved if any is invalid.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>> the updated items()
     */
    public function save(User $lecturer, Course $course, array $entries): array
    {
        [$canvasCourseId, $canvasUserId] = $this->canvasContext($lecturer, $course);
        $assignments = collect($this->gateway->assignments($canvasCourseId, $canvasUserId))->keyBy('id');
        $topicIds = Topic::where('course_id', $course->id)->where('status', 'active')->pluck('id')->all();

        $errors = [];
        $normalised = [];
        foreach ($entries as $i => $entry) {
            $row = $i + 1;
            $assignmentId = (string) ($entry['canvas_assignment_id'] ?? '');
            $criterionId = (string) ($entry['canvas_criterion_id'] ?? '');
            $topicId = $entry['topic_id'] ?? null;
            $difficulty = ($entry['difficulty'] ?? null) ?: 'medium';

            $assignment = $assignments->get($assignmentId);
            if ($assignment === null) {
                $errors[] = "Entry {$row}: Canvas item '{$assignmentId}' does not exist in this course.";
                continue;
            }
            if ($criterionId !== '' && ! collect($assignment['rubric'])->contains('id', $criterionId)) {
                $errors[] = "Entry {$row}: rubric criterion '{$criterionId}' is not part of '{$assignment['name']}'.";
            }
            if ($criterionId === '' && $topicId !== null && (float) ($assignment['points_possible'] ?? 0) <= 0) {
                $errors[] = "Entry {$row}: '{$assignment['name']}' has no points possible, so it cannot be mapped as a whole.";
            }
            if ($topicId !== null && ! in_array((int) $topicId, $topicIds, true)) {
                $errors[] = "Entry {$row}: the topic must be an active topic of this course.";
            }
            if (! in_array($difficulty, self::DIFFICULTIES, true)) {
                $errors[] = "Entry {$row}: difficulty must be easy, medium or hard.";
            }

            $normalised[] = [$assignmentId, $criterionId, $topicId === null ? null : (int) $topicId, $difficulty];
        }

        // Whole-item and criteria mappings must not be combined for one item, after applying the changes.
        $final = CanvasItemMapping::where('course_id', $course->id)->get()
            ->mapWithKeys(fn ($m) => [$m->canvas_assignment_id.'|'.$m->canvas_criterion_id => true])->all();
        foreach ($normalised as [$assignmentId, $criterionId, $topicId]) {
            $key = $assignmentId.'|'.$criterionId;
            if ($topicId === null) {
                unset($final[$key]);
            } else {
                $final[$key] = true;
            }
        }
        foreach ($assignments->keys() as $assignmentId) {
            $whole = isset($final[$assignmentId.'|']);
            $criteria = collect(array_keys($final))->contains(fn ($k) => str_starts_with($k, $assignmentId.'|') && $k !== $assignmentId.'|');
            if ($whole && $criteria) {
                $errors[] = "'{$assignments[$assignmentId]['name']}': map either the whole item or its rubric criteria, not both.";
            }
        }

        if ($errors !== []) {
            throw LecturerException::invalid('The mappings are not valid.', $errors);
        }

        DB::transaction(function () use ($lecturer, $course, $normalised) {
            foreach ($normalised as [$assignmentId, $criterionId, $topicId, $difficulty]) {
                $existing = CanvasItemMapping::where('course_id', $course->id)
                    ->where('canvas_assignment_id', $assignmentId)->where('canvas_criterion_id', $criterionId)->first();

                if ($existing === null && $topicId === null) {
                    continue;
                }
                if ($existing !== null && $existing->topic_id === $topicId && $existing->difficulty === $difficulty) {
                    continue;
                }

                DB::table('mapping_changes')->insert([
                    'course_id' => $course->id,
                    'canvas_assignment_id' => $assignmentId,
                    'canvas_criterion_id' => $criterionId,
                    'old_topic_id' => $existing?->topic_id,
                    'new_topic_id' => $topicId,
                    'old_difficulty' => $existing?->difficulty,
                    'new_difficulty' => $topicId === null ? null : $difficulty,
                    'changed_by' => $lecturer->id,
                    'created_at' => now(),
                ]);

                if ($topicId === null) {
                    $existing->delete();
                } elseif ($existing === null) {
                    CanvasItemMapping::create([
                        'course_id' => $course->id, 'canvas_assignment_id' => $assignmentId,
                        'canvas_criterion_id' => $criterionId, 'topic_id' => $topicId,
                        'difficulty' => $difficulty, 'mapped_by' => $lecturer->id,
                    ]);
                } else {
                    $existing->update(['topic_id' => $topicId, 'difficulty' => $difficulty, 'mapped_by' => $lecturer->id]);
                }
            }
        });

        return $this->items($lecturer, $course);
    }

    /** @return array{0: string, 1: string} [canvas course id, lecturer's canvas user id] */
    private function canvasContext(User $lecturer, Course $course): array
    {
        if (! $this->access->isLecturer($lecturer, $course)) {
            throw LecturerException::notFound();
        }

        $canvasCourseId = $course->canvasLink?->canvas_course_id;
        $connection = CanvasConnection::where('user_id', $lecturer->id)->whereNull('revoked_at')->first();
        if ($canvasCourseId === null || $connection === null) {
            throw LecturerException::invalid('Connect your Canvas account and link this course to Canvas first.');
        }

        return [$canvasCourseId, $connection->canvas_user_id];
    }
}
