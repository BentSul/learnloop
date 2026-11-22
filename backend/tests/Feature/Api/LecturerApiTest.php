<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** AGENTS.md §12 lecturer endpoints, run against the demo data. */
class LecturerApiTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->course = Course::where('code', 'WT101')->firstOrFail();
        $this->topic = $this->course->topics()->orderBy('id')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail());
    }

    private function questionBody(array $overrides = []): array
    {
        return $overrides + [
            'question_type' => 'mcq',
            'question_text' => 'Which tag makes a link?',
            'difficulty' => 'easy',
            'max_marks' => 2,
            'answer_data' => ['options' => [['id' => 'a', 'text' => '<a>'], ['id' => 'b', 'text' => '<p>']], 'correct_option_ids' => ['a']],
            'explanation' => 'Anchors make links.',
        ];
    }

    public function test_topics_and_questions_show_lecturer_information(): void
    {
        $this->getJson("/api/courses/{$this->course->id}/topics")->assertOk()
            ->assertJsonStructure([['id', 'course_id', 'name', 'status', 'topic_size', 'active_questions', 'warnings']]);

        $questions = $this->getJson("/api/topics/{$this->topic->id}/questions")->assertOk()
            ->assertJsonStructure([['id', 'question_type', 'question_text', 'max_marks', 'answer_data' => ['options', 'correct_option_ids'], 'used_in_attempts']])
            ->json();
        $this->assertContains(true, array_column($questions, 'used_in_attempts'), 'Demo practice answers mark some questions as used');
    }

    public function test_other_lecturers_and_students_get_404(): void
    {
        Sanctum::actingAs(User::where('email', 'prof.james.okafor@learnloop.test')->firstOrFail());
        $this->getJson("/api/courses/{$this->course->id}/topics")->assertNotFound();
        $this->getJson("/api/topics/{$this->topic->id}/questions")->assertNotFound();

        Sanctum::actingAs(User::where('email', 'jamie.lee@student.learnloop.test')->firstOrFail());
        $this->getJson("/api/topics/{$this->topic->id}/questions")->assertNotFound();
        $this->postJson("/api/topics/{$this->topic->id}/questions", $this->questionBody())->assertNotFound();
        $this->getJson("/api/courses/{$this->course->id}/overview")->assertNotFound();
        $this->getJson("/api/courses/{$this->course->id}/canvas-items")->assertNotFound();
    }

    public function test_question_create_edit_archive_and_regrade(): void
    {
        $this->postJson("/api/topics/{$this->topic->id}/questions", $this->questionBody(['max_marks' => 0]))
            ->assertUnprocessable()->assertJsonStructure(['errors']);

        $created = $this->postJson("/api/topics/{$this->topic->id}/questions", $this->questionBody())
            ->assertCreated()->assertJson(['used_in_attempts' => false])->json();

        $this->patchJson("/api/questions/{$created['id']}", ['question_text' => 'Which element makes a hyperlink?'])
            ->assertOk()->assertJson(['question_text' => 'Which element makes a hyperlink?']);

        $used = Question::where('topic_id', $this->topic->id)->whereHas('attemptItems')->firstOrFail();
        $this->patchJson("/api/questions/{$used->id}", ['max_marks' => 9])->assertUnprocessable();

        $answerData = $used->answer_data;
        $other = collect($answerData['options'])->pluck('id')->first(fn ($id) => ! in_array($id, $answerData['correct_option_ids'], true));
        $answerData['correct_option_ids'] = [$other];
        $this->postJson("/api/questions/{$used->id}/regrade", ['answer_data' => $answerData])->assertOk()
            ->assertJsonStructure(['regraded', 'unchanged']);
        $this->postJson("/api/questions/{$used->id}/regrade", [])->assertUnprocessable();

        $this->postJson("/api/questions/{$created['id']}/archive")->assertOk()->assertJson(['status' => 'inactive']);
    }

    public function test_klenam_import_upload(): void
    {
        $csv = "question,option_a,option_b,option_c,option_d,answer,difficulty,marks,explanation\n"
            ."What does CSS stand for?,Cascading Style Sheets,Computer Style Sheets,,,A,easy,1,\n"
            ."Broken row,,,,,A,easy,1,\n";

        $this->post("/api/topics/{$this->topic->id}/questions/import",
            ['file' => UploadedFile::fake()->createWithContent('klenam.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'imported')->assertJsonCount(1, 'rejected');

        $this->post("/api/topics/{$this->topic->id}/questions/import",
            ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->postJson("/api/topics/{$this->topic->id}/questions/import", [])->assertUnprocessable();
    }

    public function test_canvas_mapping_and_class_overview(): void
    {
        $items = $this->getJson("/api/courses/{$this->course->id}/canvas-items")->assertOk()->json();
        $this->assertNotEmpty($items);

        $this->putJson("/api/courses/{$this->course->id}/canvas-mappings", [])->assertUnprocessable();
        $this->putJson("/api/courses/{$this->course->id}/canvas-mappings", ['mappings' => [
            ['canvas_assignment_id' => 'no-such-item', 'canvas_criterion_id' => '', 'topic_id' => $this->topic->id, 'difficulty' => 'medium'],
        ]])->assertUnprocessable();
        $this->putJson("/api/courses/{$this->course->id}/canvas-mappings", ['mappings' => []])->assertOk();

        $overview = $this->getJson("/api/courses/{$this->course->id}/overview")->assertOk()
            ->assertJsonStructure(['topics', 'learners'])->json();
        $learnerId = $overview['learners'][0]['learner']['id'];

        $this->getJson("/api/courses/{$this->course->id}/learners/{$learnerId}")->assertOk()
            ->assertJsonStructure(['learner', 'topics']);
        $this->getJson("/api/courses/{$this->course->id}/learners/999999")->assertNotFound();
    }
}
