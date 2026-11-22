<?php

namespace Tests\Unit\Adaptive;

use App\Enums\Adaptive\Confidence;
use App\Enums\Adaptive\EvidenceAge;
use App\Enums\Adaptive\PerformanceStatus;
use App\Services\Adaptive\Calculation\RecencyClassifier;
use App\Services\Adaptive\Calculation\TopicAssessor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Spec §7.7 / §16.5 recency. */
class RecencyClassifierTest extends TestCase
{
    use BuildsEvidence;

    #[DataProvider('ageCases')]
    public function test_age_labels(string $assessedAt, EvidenceAge $expected): void
    {
        $classifier = new RecencyClassifier();
        $now = new DateTimeImmutable('2026-12-01T12:00:00Z');

        $this->assertSame($expected, $classifier->classify(new DateTimeImmutable($assessedAt), $now));
    }

    public static function ageCases(): array
    {
        return [
            'same day → recent' => ['2026-12-01T08:00:00Z', EvidenceAge::Recent],
            '30 days → recent' => ['2026-11-01T12:00:00Z', EvidenceAge::Recent],
            '31 days → older' => ['2026-10-31T12:00:00Z', EvidenceAge::Older],
            '90 days → older' => ['2026-09-02T12:00:00Z', EvidenceAge::Older],
            '91 days → outdated' => ['2026-09-01T12:00:00Z', EvidenceAge::Outdated],
            'future date → recent' => ['2026-12-05T12:00:00Z', EvidenceAge::Recent],
        ];
    }

    public function test_last_assessed_is_the_newest_item(): void
    {
        $items = [
            $this->item(5, 10, assessedAt: '2026-09-01T10:00:00Z'),
            $this->item(5, 10, assessedAt: '2026-11-15T10:00:00Z'),
            $this->item(5, 10, assessedAt: '2026-10-01T10:00:00Z'),
        ];

        $this->assertEquals(
            new DateTimeImmutable('2026-11-15T10:00:00Z'),
            (new RecencyClassifier())->lastAssessedAt($items),
        );
    }

    public function test_old_evidence_never_changes_performance_status_or_confidence(): void
    {
        $items = fn () => [$this->item(9, 10, assessedAt: '2026-01-01T10:00:00Z'), $this->item(9, 10, assessedAt: '2026-01-02T10:00:00Z')];
        $assessor = new TopicAssessor();

        $early = $assessor->assess($items(), new DateTimeImmutable('2026-01-10T00:00:00Z'));
        $late = $assessor->assess($items(), new DateTimeImmutable('2026-12-01T00:00:00Z'));

        $this->assertSame(EvidenceAge::Recent, $early->evidenceAge);
        $this->assertSame(EvidenceAge::Outdated, $late->evidenceAge);
        $this->assertSame(PerformanceStatus::Strong, $late->performance->status);
        $this->assertSame($early->performance->displayPercent, $late->performance->displayPercent);
        $this->assertSame($early->confidence->confidence, $late->confidence->confidence);
        $this->assertNotNull($late->confidence->confidence);
        $this->assertNotSame(Confidence::Limited, $late->confidence->confidence);
    }

    public function test_no_evidence_has_no_age(): void
    {
        $result = (new TopicAssessor())->assess([], new DateTimeImmutable());

        $this->assertNull($result->lastAssessedAt);
        $this->assertNull($result->evidenceAge);
    }
}
