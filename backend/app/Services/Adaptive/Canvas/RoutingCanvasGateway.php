<?php

namespace App\Services\Adaptive\Canvas;

use App\Models\CanvasConnection;

/**
 * CANVAS_ADAPTER=hybrid: one server shows the demo data and real Canvas side by side.
 * Every gateway call names the Canvas user it is made for; that user's connection decides
 * the source: accounts connected with a real token read Canvas, the seeded demo accounts
 * (adapter "fixture") read database/fixtures/canvas.
 */
final class RoutingCanvasGateway implements CanvasGateway
{
    /** @var array<string, CanvasGateway> */
    private array $byUser = [];

    public function __construct(
        private readonly FixtureCanvasGateway $fixture,
        private readonly TokenCanvasGateway $token,
    ) {
    }

    public function course(string $canvasCourseId, string $asCanvasUserId): ?array
    {
        return $this->for($asCanvasUserId)->course($canvasCourseId, $asCanvasUserId);
    }

    public function courseRole(string $canvasCourseId, string $canvasUserId): ?string
    {
        return $this->for($canvasUserId)->courseRole($canvasCourseId, $canvasUserId);
    }

    public function courseStudents(string $canvasCourseId, string $asCanvasUserId): array
    {
        return $this->for($asCanvasUserId)->courseStudents($canvasCourseId, $asCanvasUserId);
    }

    public function assignments(string $canvasCourseId, string $asCanvasUserId): array
    {
        return $this->for($asCanvasUserId)->assignments($canvasCourseId, $asCanvasUserId);
    }

    public function submissionsFor(string $canvasCourseId, string $canvasUserId): array
    {
        return $this->for($canvasUserId)->submissionsFor($canvasCourseId, $canvasUserId);
    }

    private function for(string $canvasUserId): CanvasGateway
    {
        return $this->byUser[$canvasUserId] ??= (
            CanvasConnection::where('canvas_user_id', $canvasUserId)->whereNull('revoked_at')->value('adapter') === 'token'
                ? $this->token
                : $this->fixture
        );
    }
}
