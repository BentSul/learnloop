<?php

namespace App\Services\Adaptive\Calculation;

use DateTimeImmutable;

/**
 * Runs the whole calculation core for one topic's current evidence set.
 *
 * Framework-free: callers (evidence assembler, controllers) pass in the evidence
 * and the current time; nothing here touches the database or the clock.
 */
final class TopicAssessor
{
    private readonly PerformanceCalculator $performance;
    private readonly DifficultyClassifier $difficulty;
    private readonly ConflictDetector $conflicts;
    private readonly ConfidenceCalculator $confidence;
    private readonly RecencyClassifier $recency;

    public function __construct(Thresholds $thresholds = new Thresholds())
    {
        $this->performance = new PerformanceCalculator($thresholds);
        $this->difficulty = new DifficultyClassifier($thresholds);
        $this->conflicts = new ConflictDetector($thresholds);
        $this->confidence = new ConfidenceCalculator();
        $this->recency = new RecencyClassifier($thresholds);
    }

    /**
     * @param  list<EvidenceItem>  $items  current evidence set: one item per evidence key
     */
    public function assess(array $items, DateTimeImmutable $now): TopicAssessment
    {
        $performance = $this->performance->calculate($items);
        $difficulty = $this->difficulty->classify($performance->status, $items);
        $conflicts = $this->conflicts->detect($items);
        $confidence = $this->confidence->calculate($performance, $difficulty, $conflicts);
        $lastAssessedAt = $this->recency->lastAssessedAt($items);

        return new TopicAssessment(
            performance: $performance,
            difficultyClass: $difficulty,
            confidence: $confidence,
            conflicts: $conflicts,
            lastAssessedAt: $lastAssessedAt,
            evidenceAge: $this->recency->classify($lastAssessedAt, $now),
        );
    }
}
