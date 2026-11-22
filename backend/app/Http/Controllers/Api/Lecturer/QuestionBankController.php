<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Question;
use App\Models\Topic;
use App\Services\Adaptive\CourseAccess;
use App\Services\Adaptive\Lecturer\KlenamImportService;
use App\Services\Adaptive\Lecturer\LecturerException;
use App\Services\Adaptive\Lecturer\QuestionBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AGENTS.md §12: question bank, regrade and Klenam import. The services check that the
 * user is the course's lecturer (Canvas teacher or admin) and throw LecturerException.
 * Answer keys are visible here only; these routes are never used for learners.
 */
class QuestionBankController extends Controller
{
    public function __construct(
        private readonly QuestionBankService $bank,
        private readonly CourseAccess $access,
    ) {
    }

    public function topics(Request $request, Course $course): JsonResponse
    {
        $this->assertLecturer($request, $course);

        return response()->json($course->topics()->orderBy('id')->get()->map(fn (Topic $t) => [
            'id' => $t->id,
            'course_id' => $t->course_id,
            'name' => $t->name,
            'status' => $t->status,
        ] + $this->bank->topicSummary($request->user(), $t))->values());
    }

    public function index(Request $request, Topic $topic): JsonResponse
    {
        $this->assertLecturer($request, $topic->course);

        $questions = $topic->questions()->withExists('attemptItems as used_in_attempts')->orderBy('id')->get();

        return response()->json($questions->map(fn (Question $q) => $this->present($q))->values());
    }

    public function store(Request $request, Topic $topic): JsonResponse
    {
        $question = $this->bank->create($request->user(), $topic, $request->all());

        return response()->json($this->present($question->loadExists('attemptItems as used_in_attempts')), 201);
    }

    public function update(Request $request, Question $question): JsonResponse
    {
        $question = $this->bank->update($request->user(), $question, $request->all());

        return response()->json($this->present($question->loadExists('attemptItems as used_in_attempts')));
    }

    public function archive(Request $request, Question $question): JsonResponse
    {
        $question = $this->bank->archive($request->user(), $question);

        return response()->json($this->present($question->loadExists('attemptItems as used_in_attempts')));
    }

    public function regrade(Request $request, Question $question): JsonResponse
    {
        $body = $request->validate(['answer_data' => ['required', 'array']]);

        return response()->json($this->bank->regrade($request->user(), $question, $body['answer_data']));
    }

    public function import(Request $request, Topic $topic, KlenamImportService $import): JsonResponse
    {
        $body = $request->validate([
            'file' => ['required', 'file', 'max:2048'],
            'format' => ['sometimes', 'nullable', 'in:json,csv'],
        ]);

        $format = $body['format'] ?? strtolower($request->file('file')->getClientOriginalExtension());
        if (! in_array($format, ['json', 'csv'], true)) {
            throw LecturerException::invalid('Upload a .json or .csv file, or choose the format.');
        }

        return response()->json($import->import($request->user(), $topic, $request->file('file')->get(), $format));
    }

    private function assertLecturer(Request $request, Course $course): void
    {
        if (! $this->access->isLecturer($request->user(), $course)) {
            throw LecturerException::notFound();
        }
    }

    /** @return array<string, mixed> */
    private function present(Question $q): array
    {
        return $q->makeVisible('answer_data')->toArray() + ['used_in_attempts' => (bool) ($q->used_in_attempts ?? false)];
    }
}
