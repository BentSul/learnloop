<?php

namespace App\Services\Adaptive\Recommendations;

use App\Models\Recommendation;

/**
 * Learner-facing recommendation JSON (spec §9.5 of v0.5, §10.4–§10.5).
 * Reason text is built from the stored basis, i.e. the real values that triggered it.
 */
final class RecommendationPresenter
{
    /** Suggested action per primary reason (§10.4). `key` lets React pick a button. */
    private const ACTIONS = [
        'needs_help' => ['revise_topic', 'Revise the topic, take a revision quiz, or ask for help.'],
        'getting_there' => ['revision_quiz', 'Take a revision quiz to keep improving.'],
        'strong_unconfirmed' => ['revision_quiz', 'Complete another assessment to confirm your mastery.'],
        'help_requested' => ['request_help', 'Get guided revision and support for this topic.'],
        'mastery_goal' => ['revision_quiz', 'Keep practising towards mastery.'],
        'conflict_sufficient' => ['revision_quiz', 'Take more practice or an assessment to resolve the difference.'],
        'conflict_limited' => ['revision_quiz', 'Take more practice or an assessment to resolve the difference.'],
        'evidence_outdated' => ['revision_quiz', 'Optional: take a revision quiz to refresh this topic.'],
    ];

    /** @return array<string, mixed> */
    public static function toArray(Recommendation $rec): array
    {
        $basis = $rec->basis ?? [];
        [$key, $text] = self::ACTIONS[$rec->primary_reason] ?? ['revision_quiz', 'Take a revision quiz.'];

        if ($rec->primary_reason === 'mastery_goal' && ($basis['status'] ?? null) === 'not_enough_evidence') {
            [$key, $text] = ['diagnostic', 'Begin with a practice quiz on this topic.'];
        }

        return [
            'id' => $rec->id,
            'topic' => ['id' => $rec->topic_id, 'name' => $rec->topic?->name ?? ($basis['topic_name'] ?? null)],
            'state' => $rec->state->value,
            'priority' => $rec->priority->value,
            'primary_reason' => $rec->primary_reason,
            'reason_codes' => $rec->reason_codes,
            'reasons' => self::reasons($rec->reason_codes, $basis),
            'suggested_action' => ['key' => $key, 'text' => $text],
            'performance' => [
                'status' => $basis['status'] ?? null,
                'label' => $basis['label'] ?? null,
                'performance_display' => $basis['performance_display'] ?? null,
                'earned_marks' => $basis['earned_marks'] ?? null,
                'max_marks' => $basis['max_marks'] ?? null,
            ],
            'reminder_at' => $rec->reminder_at?->toIso8601String(),
            'created_at' => $rec->created_at?->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private static function reasons(array $codes, array $basis): array
    {
        $lines = [];
        foreach ($codes as $code) {
            $lines[] = match ($code) {
                'needs_help', 'getting_there', 'strong_unconfirmed' => $basis['explanation'] ?? '',
                'help_requested' => 'You asked for help with this topic.',
                'mastery_goal' => 'You set a goal to master this topic.',
                'conflict_sufficient', 'conflict_limited' => self::conflictLine($basis, $code === 'conflict_limited'),
                'evidence_outdated' => 'Your most recent assessment on this topic was over 90 days ago.',
                default => '',
            };
        }

        return array_values(array_filter($lines, fn ($l) => $l !== ''));
    }

    private static function conflictLine(array $basis, bool $limited): string
    {
        $pair = $basis['conflict']['pairs'][0] ?? null;
        if ($pair === null) {
            return 'Your results from different sources disagree.';
        }

        $line = sprintf(
            'Your %s results (%s%%) and %s results (%s%%) disagree by %s points.',
            $pair['a']['label'], $pair['a']['performance_display'],
            $pair['b']['label'], $pair['b']['performance_display'],
            $pair['difference_display'],
        );

        return $limited ? $line.' More evidence is needed before this affects confidence.' : $line;
    }
}
