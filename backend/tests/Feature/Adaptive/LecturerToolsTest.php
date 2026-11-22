<?php

namespace Tests\Feature\Adaptive;

use App\Models\Course;
use App\Models\MarkVersion;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasSyncService;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use App\Services\Adaptive\Lecturer\CanvasMappingService;
use App\Services\Adaptive\Lecturer\ClassOverviewService;
use App\Services\Adaptive\Lecturer\KlenamImportService;
use App\Services\Adaptive\Lecturer\LecturerException;
use App\Services\Adaptive\Lecturer\QuestionBankService;
use App\Services\Adaptive\Quiz\RevisionQuizService;
use App\Services\Adaptive\TopicPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Spec §3.2–§3.6, §4.6, v0.8 §3.3 and §4.1: lecturer tools, using the demo data. */
class LecturerToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $chen;   // Web Technology lecturer (Canvas teacher 601)
    private User $okafor; // Python lecturer (Canvas teacher 602)
    private Course $webTech;
    private Topic $html;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->chen = User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail();
        $this->okafor = User::where('email', 'prof.james.okafor@learnloop.test')->firstOrFail();
        $this->webTech = Course::where('code', 'WT101')->firstOrFail();
        $this->html = Topic::where('name', 'HTML basics')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function expectLecturerError(int $status, callable $fn): LecturerException
    {
        try {
            $fn();
        } catch (LecturerException $e) {
            $this->assertSame($status, $e->status());

            return $e;
        }
        $this->fail("Expected LecturerException {$status}");
    }

    private function topicResult(string $email, string $topic): array
    {
        $learner = $this->user($email);
        $t = Topic::where('name', $topic)->firstOrFail();

        return collect((new TopicPerformanceService())->forLearnerCourse($learner, $t->course))->firstWhere('topic_id', $t->id);
    }

    // ---------------------------------------------------------------- question bank

    public function test_lecturer_creates_a_valid_question_and_invalid_ones_are_explained(): void
    {
        $bank = app(QuestionBankService::class);

        $q = $bank->create($this->chen, $this->html, [
            'question_type' => 'mcq', 'question_text' => 'Which tag makes a link?', 'max_marks' => 2,
            'answer_data' => ['options' => [['id' => 'a', 'text' => '<a>'], ['id' => 'b', 'text' => '<link>']], 'correct_option_ids' => ['a']],
        ]);
        $this->assertSame('medium', $q->difficulty->value); // D10 default
        $this->assertSame('2.00', $q->max_marks);

        $e = $this->expectLecturerError(422, fn () => $bank->create($this->chen, $this->html, [
            'question_type' => 'mcq', 'question_text' => '', 'max_marks' => 0,
            'answer_data' => ['options' => [['id' => 'a', 'text' => 'Only one']], 'correct_option_ids' => []],
        ]));
        $this->assertGreaterThanOrEqual(3, count($e->errors));
    }

    public function test_only_the_courses_lecturer_can_manage_its_questions(): void
    {
        $bank = app(QuestionBankService::class);
        $data = ['question_type' => 'true_false', 'question_text' => 'x', 'max_marks' => 1,
            'answer_data' => ['options' => [['id' => 't', 'text' => 'True'], ['id' => 'f', 'text' => 'False']], 'correct_option_ids' => ['t']]];

        $this->expectLecturerError(404, fn () => $bank->create($this->okafor, $this->html, $data));
        $this->expectLecturerError(404, fn () => $bank->create($this->user('alex.morgan@student.learnloop.test'), $this->html, $data));
    }

    public function test_edit_rules_for_a_question_that_has_been_used(): void
    {
        $bank = app(QuestionBankService::class);
        $used = $this->html->questions()->orderBy('id')->first();

        $updated = $bank->update($this->chen, $used, ['question_text' => 'Reworded question', 'difficulty' => 'medium']);
        $this->assertSame('Reworded question', $updated->question_text);

        $this->expectLecturerError(422, fn () => $bank->update($this->chen, $used, ['max_marks' => 5]));
        $this->expectLecturerError(422, fn () => $bank->update($this->chen, $used, ['answer_data' => $used->answer_data]));
        $this->expectLecturerError(422, fn () => $bank->update($this->chen, $used, ['topic_id' => Topic::where('name', 'Lists')->value('id')]));

        $fresh = $bank->create($this->chen, $this->html, [
            'question_type' => 'true_false', 'question_text' => 'Unused', 'max_marks' => 1,
            'answer_data' => ['options' => [['id' => 't', 'text' => 'True'], ['id' => 'f', 'text' => 'False']], 'correct_option_ids' => ['t']],
        ]);
        $this->assertSame('3.00', $bank->update($this->chen, $fresh, ['max_marks' => 3])->max_marks);
    }

    public function test_archived_question_keeps_its_evidence(): void
    {
        $before = $this->topicResult('jamie.lee@student.learnloop.test', 'HTML basics');

        foreach ($this->html->questions as $q) {
            app(QuestionBankService::class)->archive($this->chen, $q);
        }

        $this->assertSame($before['performance_display'], $this->topicResult('jamie.lee@student.learnloop.test', 'HTML basics')['performance_display']);
        $this->assertContains(
            'This topic has fewer than 3 active objective questions. Revision quizzes cannot be generated.',
            app(QuestionBankService::class)->topicSummary($this->chen, $this->html)['warnings'],
        );
    }

    public function test_regrade_versions_changed_marks_and_fixes_in_progress_attempts(): void
    {
        $question = $this->html->questions()->orderBy('id')->first(); // correct answer 'c' (<h1>); demo wrong answers chose 'a'
        $sam = $this->user('sam.patel@student.learnloop.test');
        $inProgress = app(RevisionQuizService::class)->createAndStart($sam, $this->html);
        $jamieBefore = $this->topicResult('jamie.lee@student.learnloop.test', 'HTML basics');

        $answerData = $question->answer_data;
        $answerData['correct_option_ids'] = ['a'];
        $result = app(QuestionBankService::class)->regrade($this->chen, $question, $answerData);

        $this->assertSame(['regraded' => 2, 'unchanged' => 0], $result); // Alex and Jamie answered 'c'
        $this->assertSame(2, MarkVersion::where('reason', 'regrade')->count());
        $this->assertSame(4, MarkVersion::where('evidence_key', "q:{$question->id}")->count()); // 2 originals + 2 regrades
        $jamieAfter = $this->topicResult('jamie.lee@student.learnloop.test', 'HTML basics');
        $this->assertLessThan($jamieBefore['performance_display'], $jamieAfter['performance_display']);

        $snapshot = DB::table('attempt_items')->where('attempt_id', $inProgress->id)->where('question_id', $question->id)->value('marking_snapshot');
        $this->assertSame(['a'], json_decode($snapshot, true)['correct_option_ids']);
    }

    public function test_regrade_cannot_add_or_reorder_options(): void
    {
        $question = $this->html->questions()->orderBy('id')->first();
        $answerData = $question->answer_data;
        $answerData['options'] = array_reverse($answerData['options']);

        $this->expectLecturerError(422, fn () => app(QuestionBankService::class)->regrade($this->chen, $question, $answerData));
    }

    // ---------------------------------------------------------------- Klenam import

    public function test_klenam_json_import_reports_each_rejected_row(): void
    {
        $json = json_encode([
            ['question' => 'What does CSS stand for?', 'options' => ['Cascading Style Sheets', 'Computer Style Sheets'], 'answer' => 'A'],
            ['question' => 'Which tag is a paragraph?', 'options' => ['<p>', '<para>', '<pg>'], 'answer' => 1, 'difficulty' => 'easy', 'marks' => 2],
            ['question' => 'HTML is a programming language.', 'type' => 'true_false', 'answer' => 'False'],
            ['question' => 'Pick the list tag', 'options' => ['<ul>', '<list>'], 'answer' => '<ul>'],
            ['question' => '', 'options' => ['a', 'b'], 'answer' => 'A'],
            ['question' => 'Mismatch', 'options' => ['x', 'y'], 'answer' => 'Z'],
            ['question' => 'Explain the DOM.', 'type' => 'open_ended', 'answer' => ''],
            ['question' => 'Which element defines the most important (largest) heading?', 'options' => ['a', 'b'], 'answer' => 'A'],
        ]);

        $result = app(KlenamImportService::class)->import($this->chen, $this->html, $json, 'json');

        $this->assertCount(4, $result['imported']);
        $this->assertSame([5, 6, 7, 8], array_column($result['rejected'], 'row'));
        $this->assertStringContainsString('already exists', $result['rejected'][3]['reason']);

        $imported = Question::whereIn('id', $result['imported'])->orderBy('id')->get();
        $this->assertSame(['klenam'], $imported->pluck('source')->unique()->values()->all());
        $this->assertSame('1.00', $imported[0]->max_marks);
        $this->assertSame('medium', $imported[0]->difficulty->value);
        $this->assertSame(['a'], $imported[1]->answer_data['correct_option_ids']);
        $this->assertSame(['f'], $imported[2]->answer_data['correct_option_ids']);
        $this->assertSame(['a'], $imported[3]->answer_data['correct_option_ids']);
    }

    public function test_klenam_csv_import(): void
    {
        $csv = "question,option_a,option_b,option_c,option_d,answer,difficulty,marks,explanation\n"
            ."Which tag makes a line break?,<br>,<lb>,<break>,,A,easy,1,The br element.\n"
            ."\"Forms use the <form> tag, right?\",,,,,True,medium,1,\n";

        $result = app(KlenamImportService::class)->import($this->chen, $this->html, $csv, 'csv');

        $this->assertCount(2, $result['imported']);
        $this->assertSame([], $result['rejected']);
        $this->assertSame('true_false', Question::find($result['imported'][1])->question_type->value);
    }

    public function test_klenam_import_rejects_bad_files_and_non_lecturers(): void
    {
        $this->expectLecturerError(422, fn () => app(KlenamImportService::class)->import($this->chen, $this->html, '{"not": "a list"}', 'json'));
        $this->expectLecturerError(404, fn () => app(KlenamImportService::class)->import($this->okafor, $this->html, '[]', 'json'));
    }

    // ---------------------------------------------------------------- Canvas mapping

    public function test_mapping_screen_lists_items_with_their_mapping_state(): void
    {
        $items = collect(app(CanvasMappingService::class)->items($this->chen, $this->webTech))->keyBy('canvas_assignment_id');

        $this->assertNull($items['2001']['mapping']);
        $this->assertSame($this->html->id, $items['2001']['criteria'][0]['mapping']['topic_id']);
        $this->assertSame('medium', $items['2002']['mapping']['difficulty']);
        $this->assertNull($items['2003']['mapping']);
    }

    public function test_new_mapping_is_logged_and_picked_up_by_the_next_sync(): void
    {
        $alex = $this->user('alex.morgan@student.learnloop.test');
        $sync = app(CanvasSyncService::class);
        $this->assertFalse($sync->needsSync($alex, $this->webTech));

        app(CanvasMappingService::class)->save($this->chen, $this->webTech, [
            ['canvas_assignment_id' => '2003', 'canvas_criterion_id' => '', 'topic_id' => $this->html->id, 'difficulty' => 'easy'],
        ]);

        $this->assertSame(1, DB::table('mapping_changes')->count());
        $this->assertTrue($sync->needsSync($alex, $this->webTech));
        $sync->syncLearnerCourse($alex, $this->webTech);

        $keys = array_map(fn ($i) => $i->evidenceKey, (new EvidenceAssembler())->forLearnerCourse($alex->id, $this->webTech->id)->forTopic($this->html->id));
        $this->assertContains('c:1001:2003', $keys);
    }

    public function test_unmapping_removes_the_evidence_but_keeps_history(): void
    {
        $alex = $this->user('alex.morgan@student.learnloop.test');
        $js = Topic::where('name', 'JavaScript logic')->firstOrFail();

        app(CanvasMappingService::class)->save($this->chen, $this->webTech, [
            ['canvas_assignment_id' => '2002', 'canvas_criterion_id' => '', 'topic_id' => null],
        ]);
        app(CanvasSyncService::class)->syncLearnerCourse($alex, $this->webTech);

        $keys = array_map(fn ($i) => $i->evidenceKey, (new EvidenceAssembler())->forLearnerCourse($alex->id, $this->webTech->id)->forTopic($js->id));
        $this->assertNotContains('c:1001:2002', $keys);
        $this->assertSame(2, DB::table('external_results')->where('learner_id', $alex->id)->where('external_item_ref', '2002')->count());
    }

    public function test_invalid_mappings_save_nothing(): void
    {
        $before = DB::table('canvas_item_mappings')->count();
        $lists = Topic::where('name', 'Lists')->firstOrFail();

        $e = $this->expectLecturerError(422, fn () => app(CanvasMappingService::class)->save($this->chen, $this->webTech, [
            ['canvas_assignment_id' => '2003', 'topic_id' => $this->html->id],                       // valid on its own
            ['canvas_assignment_id' => '2001', 'canvas_criterion_id' => '', 'topic_id' => $this->html->id], // whole + criteria
            ['canvas_assignment_id' => '9999', 'topic_id' => $this->html->id],                       // unknown item
            ['canvas_assignment_id' => '2002', 'topic_id' => $lists->id],                            // other course
        ]));

        $this->assertCount(3, $e->errors);
        $this->assertSame($before, DB::table('canvas_item_mappings')->count());
        $this->assertSame(0, DB::table('mapping_changes')->count());
    }

    // ---------------------------------------------------------------- class overview

    public function test_class_overview_counts_statuses_per_topic(): void
    {
        $overview = app(ClassOverviewService::class)->forCourse($this->chen, $this->webTech);

        $this->assertSame(['Alex Morgan', 'Jamie Lee', 'Sam Patel'], array_column(array_column($overview['learners'], 'learner'), 'name'));
        $html = collect($overview['topics'])->firstWhere('topic_name', 'HTML basics')['counts'];
        $this->assertSame(1, $html['strong']);
        $this->assertSame(1, $html['getting_there']);
        $this->assertSame(1, $html['not_enough_evidence']);
    }

    public function test_lecturers_only_see_their_own_courses_and_students(): void
    {
        $python = Course::where('code', 'PY101')->firstOrFail();
        $jamie = $this->user('jamie.lee@student.learnloop.test');

        $this->expectLecturerError(404, fn () => app(ClassOverviewService::class)->forCourse($this->chen, $python));

        $detail = app(ClassOverviewService::class)->learnerDetail($this->chen, $this->webTech, $jamie->id);
        $this->assertSame('Jamie Lee', $detail['learner']['name']);
        $this->assertCount(3, $detail['topics']);

        $this->expectLecturerError(404, fn () => app(ClassOverviewService::class)->learnerDetail($this->chen, $this->webTech, $this->okafor->id));
    }
}
