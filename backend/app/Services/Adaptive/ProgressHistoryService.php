<?php

namespace App\Services\Adaptive;

use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Calculation\EvidenceItem;
use App\Services\Adaptive\Calculation\Marks;
use App\Services\Adaptive\Calculation\PerformanceCalculator;
use App\Services\Adaptive\Calculation\TopicAssessor;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use DateTimeImmutable;

/**
 * How a learner's result in a topic built up over time, for a progress chart. Replays the
 * learner's current evidence (the result that counts for each question or Canvas item) day by
 * day: what was assessed that day, and the topic result once everything up to that day is in.
 * A retake replaces the earlier attempt, so the chart shows the evidence as it counts today.
 */
class ProgressHistoryService
{
    private const SOURCE_LABELS = ['learnloop' => 'LearnLoop practice', 'canvas' => 'Canvas', 'klenam' => 'Klenam AI'];

    private const STATUS_LABELS = [
        'not_enough_evidence' => 'Not enough evidence', 'needs_help' => 'Needs help', 'getting_there' => 'Getting there',
        'strong_unconfirmed' => 'Strong so far — more evidence needed', 'strong' => 'Strong performance',
    ];

    public function __construct(
        private readonly CourseAccess $access,
        private readonly EvidenceAssembler $assembler = new EvidenceAssembler(),
        private readonly TopicAssessor $assessor = new TopicAssessor(),
    ) {
    }

    /** @return array{topic: array{id: int, name: string}, points: list<array<string, mixed>>} */
    public function forLearnerTopic(User $learner, Topic $topic): array
    {
        if ($topic->status !== 'active' || ! $this->access->isStudent($learner, $topic->course)) {
            abort(404, 'Not found.');
        }

        $items = $this->assembler->forLearnerCourse($learner->id, $topic->course_id)->forTopic($topic->id);
        usort($items, fn (EvidenceItem $a, EvidenceItem $b) => $a->assessedAt <=> $b->assessedAt);

        $byDay = [];
        foreach ($items as $item) {
            $byDay[$item->assessedAt->format('Y-m-d')][] = $item;
        }

        $points = [];
        $soFar = [];
        foreach ($byDay as $day => $dayItems) {
            array_push($soFar, ...$dayItems);
            $earned = array_sum(array_map(fn (EvidenceItem $i) => $i->earned, $dayItems));
            $max = array_sum(array_map(fn (EvidenceItem $i) => $i->max, $dayItems));
            $cumulative = $this->assessor->assess($soFar, new DateTimeImmutable($day.' 23:59:59'))->performance;

            $points[] = [
                'date' => $day,
                'sources' => array_values(array_unique(array_map(fn (EvidenceItem $i) => self::SOURCE_LABELS[$i->sourceGroup] ?? $i->sourceGroup, $dayItems))),
                'earned_marks' => Marks::fromHundredths($earned),
                'max_marks' => Marks::fromHundredths($max),
                'day_display' => PerformanceCalculator::displayPercent($earned, $max),
                'cumulative_display' => $cumulative->displayPercent,
                'status' => $cumulative->status->value,
                'label' => self::STATUS_LABELS[$cumulative->status->value] ?? $cumulative->status->value,
            ];
        }

        return ['topic' => ['id' => $topic->id, 'name' => $topic->name], 'points' => $points];
    }
}
