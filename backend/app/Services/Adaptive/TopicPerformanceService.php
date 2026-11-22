<?php

namespace App\Services\Adaptive;

use App\Enums\Adaptive\PerformanceStatus;
use App\Models\Course;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Calculation\ConflictPair;
use App\Services\Adaptive\Calculation\Marks;
use App\Services\Adaptive\Calculation\PerformanceCalculator;
use App\Services\Adaptive\Calculation\TopicAssessment;
use App\Services\Adaptive\Calculation\TopicAssessor;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use DateTimeImmutable;

/**
 * Builds the topic performance response for React (spec §13.4, §15.2).
 * This is the only place that turns calculation results into learner-facing JSON:
 * controllers return its output unchanged.
 */
final class TopicPerformanceService
{
    private const STATUS_LABELS = [
        'needs_help' => 'Needs help',
        'getting_there' => 'Getting there',
        'strong_unconfirmed' => 'Strong so far — more evidence needed',
        'strong' => 'Strong performance',
    ];

    private const REASON_TEXT = [
        'low_coverage' => 'Fewer than 10 marks of evidence so far.',
        'mostly_basic' => 'Based mostly on easier questions.',
        'hard_only_losses' => 'Marks were mostly lost on harder questions.',
        'conflict_reduced' => 'Your results from different sources disagree.',
    ];

    private const SOURCE_LABELS = [
        'learnloop' => 'LearnLoop practice',
        'canvas' => 'Canvas',
        'klenam' => 'Klenam AI',
    ];

    public function __construct(
        private readonly EvidenceAssembler $assembler = new EvidenceAssembler(),
        private readonly TopicAssessor $assessor = new TopicAssessor(),
    ) {
    }

    /**
     * Every active topic in the course for one learner.
     *
     * @return list<array<string, mixed>>
     */
    public function forLearnerCourse(User $learner, Course $course, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        return $course->topics()->where('status', 'active')->orderBy('id')->get()
            ->map(fn (Topic $topic) => $this->present(
                $topic,
                $this->assessor->assess($evidence->forTopic($topic->id), $now),
                $evidence->pendingForTopic($topic->id),
            ))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function present(Topic $topic, TopicAssessment $result, int $pendingCount): array
    {
        $performance = $result->performance;
        $status = $performance->status;
        $hasEvidence = $performance->hasEvidence();

        return [
            'topic_id' => $topic->id,
            'topic_name' => $topic->name,
            'status' => $status->value,
            'label' => $this->label($status, $pendingCount),
            'earned_marks' => $hasEvidence ? Marks::fromHundredths($performance->earned) : null,
            'max_marks' => $hasEvidence ? Marks::fromHundredths($performance->max) : null,
            'performance_display' => $performance->displayPercent,
            'coverage_band' => $performance->coverageBand?->value,
            'difficulty_class' => $result->difficultyClass?->value,
            'confidence' => $result->confidence->confidence?->value,
            'confidence_reasons' => array_map(
                fn (string $code) => ['code' => $code, 'text' => self::REASON_TEXT[$code] ?? $code],
                $result->confidence->reasons,
            ),
            'evidence_age' => $result->evidenceAge?->value,
            'last_assessed_at' => $result->lastAssessedAt?->format(DATE_ATOM),
            'pending_count' => $pendingCount,
            'conflict' => $this->conflict($result),
            'explanation' => $this->explanation($topic, $result, $pendingCount),
        ];
    }

    private function label(PerformanceStatus $status, int $pending): string
    {
        if ($status === PerformanceStatus::NotEnoughEvidence) {
            return $pending > 0 ? 'Awaiting marking' : 'Not assessed';
        }

        return self::STATUS_LABELS[$status->value];
    }

    /** @return array<string, mixed>|null every conflicting pair with both groups' evidence (spec §8.5) */
    private function conflict(TopicAssessment $result): ?array
    {
        $pairs = $result->conflicts->conflictingPairs();
        if ($pairs === []) {
            return null;
        }

        return [
            'conflict_detected' => true,
            'confidence_reduced' => $result->conflicts->reducesConfidence(),
            'pairs' => array_map(fn (ConflictPair $p) => [
                'groups' => [$p->groupA, $p->groupB],
                'a' => [
                    'group' => $p->groupA,
                    'label' => self::SOURCE_LABELS[$p->groupA] ?? $p->groupA,
                    'earned_marks' => Marks::fromHundredths($p->earnedA),
                    'max_marks' => Marks::fromHundredths($p->maxA),
                    'performance_display' => $p->displayPercentA(),
                ],
                'b' => [
                    'group' => $p->groupB,
                    'label' => self::SOURCE_LABELS[$p->groupB] ?? $p->groupB,
                    'earned_marks' => Marks::fromHundredths($p->earnedB),
                    'max_marks' => Marks::fromHundredths($p->maxB),
                    'performance_display' => $p->displayPercentB(),
                ],
                'difference_display' => $p->displayDifference(),
                'both_meet_minimum' => $p->bothMeetMinimum,
                'qualifies_for_reduction' => $p->reducesConfidence,
            ], $pairs),
        ];
    }

    private function explanation(Topic $topic, TopicAssessment $result, int $pending): string
    {
        $p = $result->performance;

        if (! $p->hasEvidence()) {
            return $pending > 0
                ? "Your work on {$topic->name} is awaiting marking."
                : "You have not been assessed on {$topic->name} yet.";
        }

        $score = self::marks($p->earned).'/'.self::marks($p->max);
        $percent = PerformanceCalculator::displayPercent($p->earned, $p->max);

        return $p->status === PerformanceStatus::StrongUnconfirmed
            ? "You have scored {$score} ({$percent}%) on {$topic->name} so far. More assessment evidence is needed before mastery can be confirmed."
            : "You have scored {$score} ({$percent}%) on {$topic->name}.";
    }

    private static function marks(int $hundredths): string
    {
        return rtrim(rtrim(number_format($hundredths / 100, 2, '.', ''), '0'), '.');
    }
}
