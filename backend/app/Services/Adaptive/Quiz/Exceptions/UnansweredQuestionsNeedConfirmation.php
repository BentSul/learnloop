<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

/**
 * Submitting with blank questions requires explicit confirmation (spec §6.4, §13.4).
 * React shows SubmitAttemptDialog, then resubmits with confirm_unanswered: true.
 */
class UnansweredQuestionsNeedConfirmation extends QuizException
{
    public function __construct(public readonly int $unanswered)
    {
        parent::__construct(
            "You have {$unanswered} unanswered question(s). Submitting will count them as zero and replace your previous results for them."
        );
    }

    public function status(): int
    {
        return 422;
    }

    public function payload(): array
    {
        return [
            'message' => $this->getMessage(),
            'unanswered' => $this->unanswered,
            'requires_confirmation' => true,
        ];
    }
}
