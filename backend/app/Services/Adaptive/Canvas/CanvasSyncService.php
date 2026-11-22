<?php

namespace App\Services\Adaptive\Canvas;

use App\Models\CanvasConnection;
use App\Models\CanvasItemMapping;
use App\Models\Course;
use App\Models\ExternalResult;
use App\Models\ExternalResultItem;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a learner's Canvas grades into LearnLoop evidence (v0.8 §3.3–§3.6).
 *
 * - Only mapped Canvas items produce evidence; unmapped items are skipped.
 * - Whole-item mapping: score / points possible. Criteria mapping: rubric points per criterion.
 *   If an item has a whole-item mapping, that is used and criteria mappings are ignored.
 * - A new external_results version is written only when something changed (score, rubric
 *   points, posted/excused state, or the topic/difficulty from the mapping), so history is
 *   append-only and a mapping change re-derives evidence as a new version (D13).
 * - Missing work with no score is skipped; criteria-mapped work without a rubric assessment
 *   is flagged for the lecturer.
 * - All writes for one learner and course happen in one transaction; a failed sync changes
 *   nothing and is recorded in sync_runs.
 */
final class CanvasSyncService
{
    public function __construct(private readonly CanvasGateway $gateway)
    {
    }

    public function syncLearnerCourse(User $learner, Course $course, ?CarbonInterface $now = null): SyncSummary
    {
        $now ??= now();

        $connection = CanvasConnection::where('user_id', $learner->id)->whereNull('revoked_at')->first();
        if ($connection === null) {
            throw new CanvasNotConnected('Connect your Canvas account first.');
        }

        $canvasCourseId = $course->canvasLink?->canvas_course_id;
        if ($canvasCourseId === null) {
            throw new CanvasNotConnected('This course is not linked to Canvas.');
        }

        $mappingVersion = $this->mappingVersion($course); // read before the mappings are loaded

        try {
            $summary = DB::transaction(fn () => $this->sync($learner, $course, $connection, $canvasCourseId, $now));
        } catch (Throwable $e) {
            SyncRun::create([
                'user_id' => $learner->id, 'course_id' => $course->id, 'source' => 'canvas',
                'started_at' => $now, 'finished_at' => now(), 'status' => 'failed',
                'error' => $e instanceof CanvasNotConnected ? $e->getMessage() : class_basename($e),
            ]);
            throw $e;
        }

        SyncRun::create([
            'user_id' => $learner->id, 'course_id' => $course->id, 'source' => 'canvas',
            'started_at' => $now, 'finished_at' => now(), 'status' => 'succeeded',
            'summary' => $summary->toArray() + ['mapping_version' => $mappingVersion],
        ]);

        return $summary;
    }

    /** True if the last successful sync is older than config('adaptive.canvas.sync_after_minutes'). */
    public function needsSync(User $learner, Course $course, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $last = SyncRun::where('user_id', $learner->id)->where('course_id', $course->id)
            ->where('source', 'canvas')->where('status', 'succeeded')
            ->latest('finished_at')->first();

        $minutes = (int) config('adaptive.canvas.sync_after_minutes', 15);
        if ($last === null || $last->finished_at->lt($now->copy()->subMinutes($minutes))) {
            return true;
        }

        // A lecturer changed a mapping after the last sync read the mappings: re-derive now (D13).
        // Compared by change ID, not time, so changes within the same second are never missed.
        return $this->mappingVersion($course) > (int) ($last->summary['mapping_version'] ?? 0);
    }

    /** ID of the course's latest mapping change (0 if none). */
    private function mappingVersion(Course $course): int
    {
        return (int) DB::table('mapping_changes')->where('course_id', $course->id)->max('id');
    }

    private function sync(User $learner, Course $course, CanvasConnection $connection, string $canvasCourseId, CarbonInterface $now): SyncSummary
    {
        $canvasUserId = $connection->canvas_user_id;

        if ($this->gateway->courseRole($canvasCourseId, $canvasUserId) !== 'student') {
            throw new CanvasNotConnected('You are not a student in this Canvas course.');
        }

        $assignments = [];
        foreach ($this->gateway->assignments($canvasCourseId, $canvasUserId) as $assignment) {
            $assignments[$assignment['id']] = $assignment;
        }

        $mappings = CanvasItemMapping::where('course_id', $course->id)->get()->groupBy('canvas_assignment_id');
        $summary = new SyncSummary();

        foreach ($this->gateway->submissionsFor($canvasCourseId, $canvasUserId) as $submission) {
            $assignmentId = $submission['assignment_id'];
            $itemMappings = $mappings->get($assignmentId);
            $assignment = $assignments[$assignmentId] ?? null;

            if ($itemMappings === null || $assignment === null) {
                // Not mapped to a topic (v0.8 §3.3). If it was mapped before, record an empty
                // version so its old evidence stops counting; history is kept (D13).
                if ($this->hasEvidenceFrom($learner, $assignmentId)) {
                    $this->storeVersion($learner, $course, $assignmentId, $submission, [], $now);
                }
                $summary->skipped++;
                continue;
            }

            if ($submission['score'] === null && ! $submission['excused']) {
                $summary->skipped++; // missing or ungraded, no score (v0.8 §3.4)
                continue;
            }

            $items = $this->deriveItems($canvasCourseId, $assignment, $submission, $itemMappings->all());
            if ($items === null) {
                $summary->flagged[] = $assignmentId;
                continue;
            }

            $this->storeVersion($learner, $course, $assignmentId, $submission, $items, $now)
                ? $summary->created++
                : $summary->unchanged++;
        }

        return $summary;
    }

    /**
     * @param  list<CanvasItemMapping>  $mappings
     * @return list<array{evidence_key: string, topic_id: int, difficulty: string, earned_marks: string, max_marks: string}>|null
     *         null when the item cannot be turned into evidence and must be flagged
     */
    private function deriveItems(string $canvasCourseId, array $assignment, array $submission, array $mappings): ?array
    {
        $whole = collect($mappings)->firstWhere('canvas_criterion_id', '');

        if ($whole !== null) {
            $max = (float) ($assignment['points_possible'] ?? 0);
            if ($max <= 0) {
                return null;
            }

            return [[
                'evidence_key' => "c:{$canvasCourseId}:{$assignment['id']}",
                'topic_id' => (int) $whole->topic_id,
                'difficulty' => $whole->difficulty,
                'earned_marks' => self::decimal(max(0.0, (float) ($submission['score'] ?? 0))),
                'max_marks' => self::decimal($max),
            ]];
        }

        // Rubric criteria mapping.
        $criteria = collect($assignment['rubric'])->keyBy('id');
        $assessment = $submission['rubric_assessment'];
        if ($assessment === [] && ! $submission['excused']) {
            return null; // graded without a rubric assessment (v0.8 §3.3)
        }

        $items = [];
        foreach ($mappings as $mapping) {
            $criterion = $criteria->get($mapping->canvas_criterion_id);
            $points = $assessment[$mapping->canvas_criterion_id]['points'] ?? null;
            if ($criterion === null || (float) $criterion['points'] <= 0
                || ($points === null && ! $submission['excused'])) {
                return null;
            }

            $items[] = [
                'evidence_key' => "c:{$canvasCourseId}:{$assignment['id']}:{$mapping->canvas_criterion_id}",
                'topic_id' => (int) $mapping->topic_id,
                'difficulty' => $mapping->difficulty,
                'earned_marks' => self::decimal(max(0.0, (float) ($points ?? 0))),
                'max_marks' => self::decimal((float) $criterion['points']),
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['evidence_key'], $b['evidence_key']));

        return $items;
    }

    /** Writes a new version if anything changed. Returns true when a version was created. */
    private function storeVersion(User $learner, Course $course, string $assignmentId, array $submission, array $items, CarbonInterface $now): bool
    {
        $posted = $submission['posted_at'] !== null;
        $hash = hash('sha256', json_encode([
            $submission['score'], $posted, $submission['excused'],
            $submission['submitted_at'], $submission['graded_at'], $items,
        ]));

        $latest = ExternalResult::where('source', 'canvas')
            ->where('learner_id', $learner->id)
            ->where('external_item_ref', $assignmentId)
            ->orderByDesc('version')
            ->first();

        if ($latest !== null && $latest->score_hash === $hash) {
            return false;
        }

        $result = ExternalResult::create([
            'source' => 'canvas',
            'learner_id' => $learner->id,
            'course_id' => $course->id,
            'external_item_ref' => $assignmentId,
            'external_submission_ref' => isset($submission['attempt']) ? (string) $submission['attempt'] : null,
            'version' => ($latest?->version ?? 0) + 1,
            'score_hash' => $hash,
            'posted' => $posted,
            'excused' => $submission['excused'],
            'submitted_at' => $submission['submitted_at'],
            'graded_at' => $submission['graded_at'],
            'synced_at' => $now,
        ]);

        foreach ($items as $item) {
            ExternalResultItem::create($item + ['external_result_id' => $result->id]);
        }

        return true;
    }

    /** True if the latest stored version of this item still produces evidence items. */
    private function hasEvidenceFrom(User $learner, string $assignmentId): bool
    {
        $latest = ExternalResult::where('source', 'canvas')->where('learner_id', $learner->id)
            ->where('external_item_ref', $assignmentId)->orderByDesc('version')->first();

        return $latest !== null && $latest->items()->exists();
    }

    private static function decimal(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
