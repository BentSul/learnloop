<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TopicResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** YouTube suggestions and QuizAPI questions: off without keys, reviewed before students see them. */
class LearningSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->topic = Topic::where('name', 'JavaScript logic')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail());
    }

    private function keys(): void
    {
        config(['adaptive.learning_sources.youtube_api_key' => 'yt-key', 'adaptive.learning_sources.quizapi_key' => 'qa-key']);
    }

    public function test_everything_is_off_without_keys(): void
    {
        Http::fake();
        $this->getJson('/api/learning-sources')->assertExactJson(['video_suggestions' => false, 'question_search' => false]);
        $this->postJson("/api/topics/{$this->topic->id}/resources/suggest-videos")->assertStatus(409);
        $this->getJson("/api/topics/{$this->topic->id}/question-search?tags=javascript")->assertStatus(409);
        Http::assertNothingSent();

        $this->keys();
        $this->getJson('/api/learning-sources')->assertExactJson(['video_suggestions' => true, 'question_search' => true]);
    }

    public function test_video_suggestions_wait_for_approval(): void
    {
        $this->keys();
        Http::fake(['https://www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [
            ['id' => ['videoId' => 'PkZNo7MFNFg'], 'snippet' => ['title' => 'JavaScript &amp; Logic', 'channelTitle' => 'freeCodeCamp.org']],
            ['id' => ['videoId' => 'bad id!'], 'snippet' => ['title' => 'Broken']],
        ]])]);

        $list = $this->postJson("/api/topics/{$this->topic->id}/resources/suggest-videos")->assertOk()->json('data');
        $suggestion = collect($list)->firstWhere('status', 'suggested');
        $this->assertSame('JavaScript & Logic', $suggestion['title']);
        $this->assertSame('https://www.youtube.com/watch?v=PkZNo7MFNFg', $suggestion['url']);
        $this->assertSame(1, TopicResource::where('status', 'suggested')->count(), 'invalid IDs are skipped');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'safeSearch=strict') && str_contains($r->url(), 'key=yt-key'));

        // Students don't see it yet.
        $course = Course::where('code', 'WT101')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'jamie.lee@student.learnloop.test')->firstOrFail());
        $js = collect($this->getJson("/api/me/courses/{$course->id}/topics")->json('data'))->firstWhere('topic_name', 'JavaScript logic');
        $this->assertNotContains('JavaScript & Logic', array_column($js['resources'], 'title'));

        // The lecturer approves it; now they do.
        Sanctum::actingAs(User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail());
        $this->patchJson("/api/resources/{$suggestion['id']}", ['status' => 'published'])->assertOk()->assertJson(['status' => 'published']);
        Sanctum::actingAs(User::where('email', 'jamie.lee@student.learnloop.test')->firstOrFail());
        $js = collect($this->getJson("/api/me/courses/{$course->id}/topics")->json('data'))->firstWhere('topic_name', 'JavaScript logic');
        $this->assertContains('JavaScript & Logic', array_column($js['resources'], 'title'));
    }

    public function test_video_search_is_limited_to_once_a_day_per_topic_and_explains_quota_errors(): void
    {
        $this->keys();
        Http::fake(['https://www.googleapis.com/youtube/v3/search*' => Http::sequence()
            ->push(['error' => ['message' => 'quotaExceeded']], 403)
            ->push(['items' => []])]);

        $this->postJson("/api/topics/{$this->topic->id}/resources/suggest-videos")->assertStatus(502)
            ->assertJsonFragment(['message' => 'YouTube refused the request: the daily limit may be used up or the API key is not valid.']);
        $this->postJson("/api/topics/{$this->topic->id}/resources/suggest-videos")->assertOk(); // a failed search doesn't use up the day
        $this->postJson("/api/topics/{$this->topic->id}/resources/suggest-videos")->assertStatus(409);
    }

    public function test_question_search_reads_both_quizapi_formats_and_imports_selected(): void
    {
        $this->keys();
        Http::fake(['https://quizapi.io/*' => Http::sequence()
            ->push(['success' => true, 'meta' => ['total' => 2], 'data' => [
                ['id' => 'q_1', 'text' => 'Which keyword declares a block-scoped variable?', 'type' => 'MULTIPLE_CHOICE', 'difficulty' => 'EASY',
                    'explanation' => 'let is block scoped.', 'tags' => ['javascript'],
                    'answers' => [['id' => 'a1', 'text' => 'var', 'isCorrect' => false], ['id' => 'a2', 'text' => 'let', 'isCorrect' => true], ['id' => 'a3', 'text' => 'function', 'isCorrect' => false]]],
                ['id' => 'q_2', 'text' => 'Broken: no correct answer', 'answers' => [['text' => 'x', 'isCorrect' => false], ['text' => 'y', 'isCorrect' => false]]],
            ]])
            ->push([
                ['id' => 77, 'question' => 'What does === compare?', 'difficulty' => 'Medium', 'tags' => [['name' => 'JavaScript']],
                    'answers' => ['answer_a' => 'Value only', 'answer_b' => 'Value and type', 'answer_c' => null],
                    'correct_answers' => ['answer_a_correct' => 'false', 'answer_b_correct' => 'true', 'answer_c_correct' => 'false']],
            ])]);

        $new = $this->getJson("/api/topics/{$this->topic->id}/question-search?tags=javascript&difficulty=easy&limit=5")->assertOk()->json('data');
        $this->assertCount(1, $new, 'questions without a correct answer are dropped');
        $this->assertSame(['var', 'let', 'function'], $new[0]['options']);
        $this->assertSame([1], $new[0]['correct']);
        $this->assertSame('easy', $new[0]['difficulty']);
        $this->assertFalse($new[0]['already_in_bank']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer qa-key')
            && str_contains(urldecode($r->url()), 'difficulty=EASY') && str_contains(urldecode($r->url()), 'tags=javascript'));

        $old = $this->getJson("/api/topics/{$this->topic->id}/question-search?tags=javascript")->assertOk()->json('data');
        $this->assertSame(['Value only', 'Value and type'], $old[0]['options']);
        $this->assertSame([1], $old[0]['correct']);
        $this->assertSame(['JavaScript'], $old[0]['tags']);

        $result = $this->postJson("/api/topics/{$this->topic->id}/question-search/import", ['questions' => [$new[0], $old[0], $new[0],
            ['question' => 'Bad', 'options' => ['only one'], 'correct' => [0]]]])->assertOk()->json();
        $this->assertCount(2, $result['imported']);
        $this->assertSame([['row' => 3, 'reason' => 'Already in this topic.'], ['row' => 4, 'reason' => 'Needs 2–6 options and at least one correct answer.']], $result['rejected']);

        $q = Question::findOrFail($result['imported'][0])->makeVisible('answer_data');
        $this->assertSame('quizapi', $q->source);
        $this->assertSame(['b'], $q->answer_data['correct_option_ids']);
        $this->assertSame('let is block scoped.', $q->explanation);
    }

    public function test_question_search_errors_and_access(): void
    {
        $this->keys();
        Http::fake(['https://quizapi.io/*' => Http::sequence()->push(['message' => 'Unauthenticated'], 401)->push([], 404)]);
        $this->getJson("/api/topics/{$this->topic->id}/question-search?tags=php")->assertStatus(502)
            ->assertJsonFragment(['message' => 'QuizAPI refused the request: check the QuizAPI key.']);
        $this->getJson("/api/topics/{$this->topic->id}/question-search?tags=php")->assertOk()->assertExactJson(['data' => []]);

        Sanctum::actingAs(User::where('email', 'jamie.lee@student.learnloop.test')->firstOrFail());
        $this->getJson("/api/topics/{$this->topic->id}/question-search?tags=php")->assertNotFound();
        $this->postJson("/api/topics/{$this->topic->id}/resources/suggest-videos")->assertNotFound();
        $this->postJson("/api/topics/{$this->topic->id}/question-search/import", ['questions' => [['question' => 'x']]])->assertNotFound();
    }

    public function test_clients_cannot_set_arbitrary_question_sources(): void
    {
        $id = $this->postJson("/api/topics/{$this->topic->id}/questions", [
            'question_type' => 'true_false', 'question_text' => 'JS is single-threaded?', 'max_marks' => 1, 'source' => str_repeat('x', 40),
            'answer_data' => ['options' => [['id' => 't', 'text' => 'True'], ['id' => 'f', 'text' => 'False']], 'correct_option_ids' => ['t']],
        ])->assertCreated()->json('id');
        $this->assertSame('learnloop', Question::find($id)->source);
    }
}
