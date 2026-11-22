<?php

namespace App\Services\Adaptive\Recommendations;

use App\Enums\Adaptive\EvidenceAge;
use App\Enums\Adaptive\PerformanceStatus;
use App\Enums\Adaptive\Priority;
use App\Services\Adaptive\Calculation\TopicAssessment;

/**
 * Which recommendation a topic needs, if any (spec §10.2–§10.4, §10.6). Pure: no database.
 *
 * Each applicable reason contributes a priority from the §10.3 table; the recommendation takes
 * the highest. No applicable reason → no recommendation (e.g. Strong with nothing else, D3 default).
 */
final class RecommendationRules
{
    /** Reason codes in tie-break order (§10.2). */
    public const REASON_ORDER = [
        'needs_help', 'getting_there', 'strong_unconfirmed', 'help_requested',
        'mastery_goal', 'conflict_sufficient', 'conflict_limited', 'evidence_outdated',
    ];

    /** §10.3 priority table: reason → status → priority. Missing entries do not apply. */
    private const TABLE = [
        'needs_help' => ['needs_help' => Priority::High],
        'getting_there' => ['getting_there' => Priority::Medium],
        'strong_unconfirmed' => ['strong_unconfirmed' => Priority::Low],
        'help_requested' => [
            'needs_help' => Priority::High, 'getting_there' => Priority::High,
            'strong_unconfirmed' => Priority::Medium, 'strong' => Priority::Medium, 'not_enough_evidence' => Priority::Medium,
        ],
        'mastery_goal' => [
            'needs_help' => Priority::High, 'getting_there' => Priority::Medium,
            'strong_unconfirmed' => Priority::Low, 'strong' => Priority::Low, 'not_enough_evidence' => Priority::Low,
        ],
        'conflict_sufficient' => [
            'needs_help' => Priority::High, 'getting_there' => Priority::High,
            'strong_unconfirmed' => Priority::High, 'strong' => Priority::High,
        ],
        'conflict_limited' => [
            'needs_help' => Priority::Medium, 'getting_there' => Priority::Medium,
            'strong_unconfirmed' => Priority::Medium, 'strong' => Priority::Medium,
        ],
        'evidence_outdated' => ['strong' => Priority::Optional],
    ];

    public function desired(int $topicId, TopicAssessment $assessment, bool $helpRequested, bool $masteryGoal): ?DesiredRecommendation
    {
        $status = $assessment->performance->status;
        $candidates = [];

        // Status reasons.
        if (in_array($status, [PerformanceStatus::NeedsHelp, PerformanceStatus::GettingThere, PerformanceStatus::StrongUnconfirmed], true)) {
            $candidates[] = $status->value;
        }
        if ($helpRequested) {
            $candidates[] = 'help_requested';
        }
        if ($masteryGoal) {
            $candidates[] = 'mastery_goal';
        }
        if ($assessment->conflicts->conflictDetected()) {
            $candidates[] = $assessment->conflicts->reducesConfidence() ? 'conflict_sufficient' : 'conflict_limited';
        }
        if ($status === PerformanceStatus::Strong && $assessment->evidenceAge === EvidenceAge::Outdated) {
            $candidates[] = 'evidence_outdated';
        }

        $reasons = [];
        foreach ($candidates as $code) {
            $priority = self::TABLE[$code][$status->value] ?? null;
            if ($priority !== null) {
                $reasons[$code] = $priority;
            }
        }

        if ($reasons === []) {
            return null;
        }

        $codes = array_values(array_filter(self::REASON_ORDER, fn ($c) => isset($reasons[$c])));
        $priority = Priority::highest(...array_values($reasons));
        $primary = null;
        foreach ($codes as $code) { // first code in §10.2 order that carries the highest priority
            if ($reasons[$code] === $priority) {
                $primary = $code;
                break;
            }
        }

        $sorted = $codes;
        sort($sorted);

        return new DesiredRecommendation(
            signature: "{$topicId}|{$status->value}|".implode(',', $sorted),
            status: $status->value,
            reasonCodes: $codes,
            primaryReason: $primary,
            priority: $priority,
        );
    }
}
