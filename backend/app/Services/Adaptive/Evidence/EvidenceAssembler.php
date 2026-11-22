<?php

namespace App\Services\Adaptive\Evidence;

use App\Enums\Adaptive\AttemptStatus;
use App\Enums\Adaptive\Difficulty;
use App\Enums\Adaptive\MarkVersionStatus;
use App\Services\Adaptive\Calculation\EvidenceItem;
use App\Services\Adaptive\Calculation\Marks;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds a learner's current evidence set for a course (spec v0.7 §6.6, v0.8 §3.4–§3.5).
 *
 * LearnLoop quiz evidence:
 *   - only submitted / auto_closed attempts (voided and in-progress never count)
 *   - per answer, the highest-numbered version with status auto or approved
 *   - per evidence key, the answer with the greatest (finalised_at, attempt_id)
 *     → re-marking an old attempt never overrides a newer attempt (§6.3)
 *     → a pending or rejected newer answer never replaces an earlier eligible one
 *
 * External (Canvas / Klenam) evidence:
 *   - per (source, item), the most recent POSTED version counts; a newer unposted
 *     version adds to pending_count instead (like a pending mark)
 *   - an excused current result contributes nothing
 *   - per evidence key, the result with the latest assessment date
 *
 * Two queries per source, whatever the number of topics (no N+1).
 */
final class EvidenceAssembler
{
    public function forLearnerCourse(int $learnerId, int $courseId): CourseEvidence
    {
        $items = [];
        $pending = [];

        $this->collectQuizEvidence($learnerId, $courseId, $items, $pending);
        $this->collectExternalEvidence($learnerId, $courseId, $items, $pending);

        // One item per evidence key: keep the candidate with the latest ordering value.
        $byTopic = [];
        foreach ($items as $candidate) {
            /** @var EvidenceItem $item */
            [$item, $topicId] = [$candidate['item'], $candidate['topic_id']];
            $current = $byTopic[$topicId][$item->evidenceKey] ?? null;
            if ($current === null || $candidate['order'] > $current['order']) {
                $byTopic[$topicId][$item->evidenceKey] = $candidate;
            }
        }

        $itemsByTopic = [];
        foreach ($byTopic as $topicId => $keyed) {
            ksort($keyed);
            $itemsByTopic[$topicId] = array_values(array_map(fn ($c) => $c['item'], $keyed));
        }
        ksort($itemsByTopic);
        ksort($pending);

        return new CourseEvidence($itemsByTopic, $pending);
    }

    /**
     * @param  array<int, array{item: EvidenceItem, topic_id: int, order: string}>  $items
     * @param  array<int, int>  $pending
     */
    private function collectQuizEvidence(int $learnerId, int $courseId, array &$items, array &$pending): void
    {
        $rows = DB::table('mark_versions as mv')
            ->join('answers as an', 'an.id', '=', 'mv.answer_id')
            ->join('attempts as at', 'at.id', '=', 'an.attempt_id')
            ->join('topics as t', 't.id', '=', 'mv.topic_id')
            ->where('at.learner_id', $learnerId)
            ->where('t.course_id', $courseId)
            ->whereIn('at.status', AttemptStatus::counting())
            ->orderBy('mv.answer_id')
            ->orderBy('mv.version')
            ->get([
                'mv.answer_id', 'mv.version', 'mv.status', 'mv.earned_marks', 'mv.max_marks',
                'mv.topic_id', 'mv.difficulty', 'mv.source_group', 'mv.evidence_key',
                'mv.finalised_at', 'at.id as attempt_id',
            ]);

        $eligibleStatuses = MarkVersionStatus::eligible();
        $byAnswer = [];
        foreach ($rows as $row) {
            $byAnswer[$row->answer_id][] = $row; // ordered by version ascending
        }

        foreach ($byAnswer as $versions) {
            $latest = end($versions);
            if (! in_array($latest->status, $eligibleStatuses, true)) {
                // Newest version still pending or rejected (§5.8).
                $pending[$latest->topic_id] = ($pending[$latest->topic_id] ?? 0) + 1;
            }

            $eligible = null;
            foreach ($versions as $version) {
                if (in_array($version->status, $eligibleStatuses, true)) {
                    $eligible = $version; // highest eligible version wins
                }
            }
            if ($eligible === null) {
                continue;
            }

            $finalisedAt = Carbon::parse($eligible->finalised_at)->toDateTimeImmutable();
            $items[] = [
                'topic_id' => (int) $eligible->topic_id,
                'order' => $this->orderKey($finalisedAt, (int) $eligible->attempt_id),
                'item' => new EvidenceItem(
                    $eligible->evidence_key,
                    $eligible->source_group,
                    Difficulty::fromNullable($eligible->difficulty),
                    Marks::toHundredths($eligible->earned_marks),
                    Marks::toHundredths($eligible->max_marks),
                    $finalisedAt,
                ),
            ];
        }

        // Answered items in finalised attempts that have no mark version yet (awaiting marking).
        $unmarked = DB::table('answers as an')
            ->join('attempts as at', 'at.id', '=', 'an.attempt_id')
            ->join('attempt_items as ai', function ($join) {
                $join->on('ai.attempt_id', '=', 'an.attempt_id')->on('ai.question_id', '=', 'an.question_id');
            })
            ->join('topics as t', 't.id', '=', 'ai.topic_id_snapshot')
            ->leftJoin('mark_versions as mv', 'mv.answer_id', '=', 'an.id')
            ->where('at.learner_id', $learnerId)
            ->where('t.course_id', $courseId)
            ->whereIn('at.status', AttemptStatus::counting())
            ->whereNull('mv.id')
            ->get(['ai.topic_id_snapshot as topic_id']);

        foreach ($unmarked as $row) {
            $pending[(int) $row->topic_id] = ($pending[(int) $row->topic_id] ?? 0) + 1;
        }
    }

    /**
     * @param  array<int, array{item: EvidenceItem, topic_id: int, order: string}>  $items
     * @param  array<int, int>  $pending
     */
    private function collectExternalEvidence(int $learnerId, int $courseId, array &$items, array &$pending): void
    {
        $results = DB::table('external_results')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->orderBy('source')
            ->orderBy('external_item_ref')
            ->orderBy('version')
            ->get();

        if ($results->isEmpty()) {
            return;
        }

        $itemRows = DB::table('external_result_items')
            ->whereIn('external_result_id', $results->pluck('id'))
            ->get()
            ->groupBy('external_result_id');

        $byItem = [];
        foreach ($results as $result) {
            $byItem[$result->source."\0".$result->external_item_ref][] = $result; // ascending versions
        }

        foreach ($byItem as $versions) {
            $latest = end($versions);

            if (! $latest->posted) {
                // A newer grade exists but is not yet visible to the learner (v0.8 §3.4).
                foreach ($itemRows->get($latest->id, collect()) as $row) {
                    $pending[(int) $row->topic_id] = ($pending[(int) $row->topic_id] ?? 0) + 1;
                }
            }

            $current = null;
            foreach ($versions as $version) {
                if ($version->posted) {
                    $current = $version; // most recent posted version
                }
            }
            if ($current === null || $current->excused) {
                continue;
            }

            $assessedAt = Carbon::parse($current->submitted_at ?? $current->graded_at ?? $current->synced_at)
                ->toDateTimeImmutable();

            foreach ($itemRows->get($current->id, collect()) as $row) {
                $earned = Marks::toHundredths($row->earned_marks);
                $max = Marks::toHundredths($row->max_marks);

                $items[] = [
                    'topic_id' => (int) $row->topic_id,
                    'order' => $this->orderKey($assessedAt, (int) $current->id),
                    'item' => new EvidenceItem(
                        $row->evidence_key,
                        $current->source,
                        Difficulty::fromNullable($row->difficulty),
                        min($earned, $max), // extra credit is capped at points possible (v0.8 §3.4)
                        $max,
                        $assessedAt,
                    ),
                ];
            }
        }
    }

    /** Sortable string: timestamp first, then ID as the tie-breaker (§6.3). */
    private function orderKey(DateTimeImmutable $at, int $id): string
    {
        return sprintf('%012d-%012d', $at->getTimestamp(), $id);
    }
}
