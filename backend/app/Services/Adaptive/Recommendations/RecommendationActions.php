<?php

namespace App\Services\Adaptive\Recommendations;

use App\Enums\Adaptive\RecommendationState as State;
use App\Models\Recommendation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Learner actions on a recommendation (spec §10.8–§10.10, §10.14).
 *
 * Each action locks the row, re-checks ownership and state, then changes state and writes
 * the event in one transaction. Repeating an action already applied returns the row unchanged;
 * any other invalid transition is a 409.
 */
final class RecommendationActions
{
    public function __construct(private readonly RecommendationEngine $engine = new RecommendationEngine())
    {
    }

    public function accept(User $learner, int $id, ?CarbonInterface $now = null): Recommendation
    {
        $now ??= now();

        return $this->locked($learner, $id, function (Recommendation $rec) use ($now) {
            return match ($rec->state) {
                State::Accepted => $rec,
                State::Active => $this->apply($rec, State::Accepted, $now, ['accepted_at' => $now]),
                default => throw RecommendationException::invalidTransition($rec->state->value, 'accepted'),
            };
        });
    }

    /** "Remind me later": reminder must be in the future and at most config('adaptive.recommendations.reminder_max_days') ahead. */
    public function postpone(User $learner, int $id, string $reminderAt, ?CarbonInterface $now = null): Recommendation
    {
        $now ??= now();
        $when = $this->parseReminder($reminderAt, $now);

        return $this->locked($learner, $id, function (Recommendation $rec) use ($now, $when) {
            return match ($rec->state) {
                State::Active, State::Postponed => $this->apply($rec, State::Postponed, $now, ['reminder_at' => $when]),
                default => throw RecommendationException::invalidTransition($rec->state->value, 'postponed'),
            };
        });
    }

    /** "Never again" (T3). Never affects performance or status. */
    public function dismiss(User $learner, int $id, ?CarbonInterface $now = null): Recommendation
    {
        $now ??= now();

        return $this->locked($learner, $id, function (Recommendation $rec) use ($now) {
            return match ($rec->state) {
                State::Dismissed => $rec,
                State::Active, State::Accepted, State::Postponed => $this->apply($rec, State::Dismissed, $now, ['dismissed_at' => $now]),
                default => throw RecommendationException::invalidTransition($rec->state->value, 'dismissed'),
            };
        });
    }

    /**
     * Restore a dismissed recommendation. It becomes active only if it still applies
     * (its signature equals the current desired one) and no other recommendation is open
     * for the topic; otherwise it stays dismissed and $applies is false (§10.8).
     *
     * @return array{recommendation: Recommendation, restored: bool, message: string}
     */
    public function restore(User $learner, int $id, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $rec = $this->findForLearner($learner, $id);
        $desired = $this->engine->desiredForCourse($learner, $rec->topic->course, $now);
        $wantSignature = $desired[$rec->topic_id]['desired']?->signature ?? null;

        return DB::transaction(function () use ($learner, $id, $now, $wantSignature) {
            $rec = $this->lockForLearner($learner, $id);

            if ($rec->state !== State::Dismissed) {
                throw RecommendationException::invalidTransition($rec->state->value, 'restored');
            }

            $openExists = Recommendation::where('learner_id', $learner->id)->where('topic_id', $rec->topic_id)
                ->whereIn('state', State::openValues())->exists();

            if ($wantSignature !== $rec->signature || $openExists) {
                return [
                    'recommendation' => $rec,
                    'restored' => false,
                    'message' => $openExists
                        ? 'You already have a newer recommendation for this topic.'
                        : 'This recommendation no longer applies to your current results.',
                ];
            }

            $this->apply($rec, State::Active, $now, ['restored_at' => $now]);

            return ['recommendation' => $rec, 'restored' => true, 'message' => 'Recommendation restored.'];
        });
    }

    public function findForLearner(User $learner, int $id): Recommendation
    {
        return Recommendation::where('learner_id', $learner->id)->whereKey($id)->first()
            ?? throw RecommendationException::notFound();
    }

    private function locked(User $learner, int $id, callable $fn): Recommendation
    {
        return DB::transaction(fn () => $fn($this->lockForLearner($learner, $id)));
    }

    private function lockForLearner(User $learner, int $id): Recommendation
    {
        return Recommendation::where('learner_id', $learner->id)->whereKey($id)->lockForUpdate()->first()
            ?? throw RecommendationException::notFound();
    }

    private function apply(Recommendation $rec, State $to, CarbonInterface $now, array $extra): Recommendation
    {
        $this->engine->transition($rec, $to, 'learner', $now, $extra);

        return $rec;
    }

    private function parseReminder(string $value, CarbonInterface $now): Carbon
    {
        try {
            $when = Carbon::parse($value)->utc(); // stored in UTC (§10.10)
        } catch (\Throwable) {
            throw RecommendationException::invalidReminder('Reminder must be an ISO 8601 date and time.');
        }

        $maxDays = (int) config('adaptive.recommendations.reminder_max_days', 60);
        if ($when->lte($now)) {
            throw RecommendationException::invalidReminder('Choose a reminder time in the future.');
        }
        if ($when->gt($now->copy()->addDays($maxDays))) {
            throw RecommendationException::invalidReminder("Reminders can be at most {$maxDays} days ahead.");
        }

        return $when;
    }
}
