<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\CanvasConnection;
use App\Models\CanvasItemMapping;
use App\Models\Course;
use App\Models\CourseLink;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasSyncService;
use App\Services\Adaptive\Quiz\AttemptService;
use Illuminate\Database\Seeder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Demo data for the adaptive engine: two courses linked to the Canvas fixtures,
 * topics, a 28-question bank, practice sets, Canvas mappings, and three learners
 * whose practice answers and Canvas grades show the engine's behaviours:
 *
 * - Alex Morgan: strong across topics.
 * - Jamie Lee: needs help in Lists and Dictionaries; Functions has conflicting evidence
 *   (8/8 practice vs 3/10 Canvas); an unposted Canvas grade shows "awaiting marking".
 * - Sam Patel: little evidence; CSS is "strong so far" (8/8 but under 10 marks);
 *   an excused Canvas item; a Canvas item graded without a rubric is flagged.
 *
 * Requires UserSeeder. Uses the fixture Canvas adapter (config adaptive.canvas.adapter).
 */
class AdaptiveDemoSeeder extends Seeder
{
    /** Canvas user IDs used in database/fixtures/canvas. */
    private const CANVAS_IDS = [
        'dr.sarah.chen@learnloop.test' => '601',
        'prof.james.okafor@learnloop.test' => '602',
        'alex.morgan@student.learnloop.test' => '501',
        'jamie.lee@student.learnloop.test' => '502',
        'sam.patel@student.learnloop.test' => '503',
    ];

    /**
     * Practice answers: per learner and topic, whether questions 1–4 (easy, medium, medium, hard)
     * are answered correctly. Missing topics are not attempted.
     */
    private const PRACTICE = [
        'alex.morgan@student.learnloop.test' => [
            'HTML basics' => [1, 1, 1, 1], 'CSS' => [1, 1, 1, 1], 'JavaScript logic' => [1, 1, 1, 1],
            'Python basics' => [1, 1, 1, 1], 'Functions' => [1, 1, 1, 1], 'Lists' => [1, 1, 1, 1], 'Dictionaries' => [1, 1, 1, 1],
        ],
        'jamie.lee@student.learnloop.test' => [
            'HTML basics' => [1, 1, 1, 0], 'CSS' => [1, 1, 0, 0], 'JavaScript logic' => [0, 1, 1, 0],
            'Python basics' => [1, 1, 1, 0], 'Functions' => [1, 1, 1, 1], 'Lists' => [1, 0, 0, 0], 'Dictionaries' => [1, 1, 0, 0],
        ],
        'sam.patel@student.learnloop.test' => [
            'CSS' => [1, 1, 1, 1], 'Python basics' => [1, 1, 0, 1],
        ],
    ];

    public function run(): void
    {
        $lecturers = [
            'WT101' => User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail(),
            'PY101' => User::where('email', 'prof.james.okafor@learnloop.test')->firstOrFail(),
        ];

        foreach ($this->courses() as $code => $courseData) {
            $course = Course::create(['name' => $courseData['name'], 'code' => $code]);
            CourseLink::create(['course_id' => $course->id, 'canvas_course_id' => $courseData['canvas_course_id']]);

            foreach ($courseData['topics'] as $topicName => $topicData) {
                $topic = Topic::create([
                    'course_id' => $course->id,
                    'name' => $topicName,
                    'description' => $topicData['description'],
                    'learning_outcomes' => $topicData['outcomes'],
                ]);

                $practice = Assessment::create([
                    'course_id' => $course->id,
                    'assessment_type' => 'practice',
                    'title' => "{$topicName} practice",
                    'created_by' => $lecturers[$code]->id,
                ]);

                foreach ($topicData['questions'] as $position => $q) {
                    $question = Question::create($q + [
                        'topic_id' => $topic->id,
                        'created_by' => $lecturers[$code]->id,
                    ]);
                    AssessmentItem::create([
                        'assessment_id' => $practice->id,
                        'question_id' => $question->id,
                        'position' => $position + 1,
                    ]);
                }

                foreach ($topicData['canvas'] as $mapping) {
                    [$assignmentId, $criterionId, $difficulty] = $mapping;
                    CanvasItemMapping::create([
                        'course_id' => $course->id,
                        'canvas_assignment_id' => $assignmentId,
                        'canvas_criterion_id' => $criterionId,
                        'topic_id' => $topic->id,
                        'difficulty' => $difficulty,
                        'source_group' => $mapping[3] ?? 'canvas',
                        'mapped_by' => $lecturers[$code]->id,
                    ]);
                }
            }
        }

        foreach (self::CANVAS_IDS as $email => $canvasUserId) {
            CanvasConnection::create([
                'user_id' => User::where('email', $email)->firstOrFail()->id,
                'canvas_user_id' => $canvasUserId,
                'adapter' => 'fixture',
                'connected_at' => now(),
            ]);
        }

        $this->takePracticeQuizzes();
        $this->syncCanvas();
    }

    private function takePracticeQuizzes(): void
    {
        $service = new AttemptService(new Randomizer(new Mt19937(2026)));

        foreach (self::PRACTICE as $email => $topics) {
            $learner = User::where('email', $email)->firstOrFail();
            $daysAgo = 6;

            foreach ($topics as $topicName => $pattern) {
                $topic = Topic::where('name', $topicName)->firstOrFail();
                $practice = Assessment::where('topic_id', null)->where('title', "{$topicName} practice")->firstOrFail();
                $attempt = $service->start($learner, $practice, now()->subDays($daysAgo)->subMinutes(20));

                $questions = $topic->questions()->orderBy('id')->get();
                foreach ($questions as $i => $question) {
                    $correct = $question->answer_data['correct_option_ids'][0];
                    $wrong = collect($question->answer_data['options'])->pluck('id')->first(fn ($id) => $id !== $correct);
                    $service->saveAnswer($learner, $attempt, $question->id, ['option_ids' => [$pattern[$i] ? $correct : $wrong]]);
                }

                $service->submit($learner, $attempt, false, now()->subDays($daysAgo));
                $daysAgo = max(1, $daysAgo - 1);
            }
        }
    }

    private function syncCanvas(): void
    {
        $sync = app(CanvasSyncService::class);

        foreach (array_keys(self::PRACTICE) as $email) {
            $learner = User::where('email', $email)->firstOrFail();
            foreach (Course::all() as $course) {
                $sync->syncLearnerCourse($learner, $course);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function courses(): array
    {
        return [
            'WT101' => [
                'name' => 'Web Technology',
                'canvas_course_id' => '1001',
                'topics' => [
                    'HTML basics' => [
                        'description' => 'Document structure, common elements and semantic HTML.',
                        'outcomes' => ['Structure a valid HTML document', 'Choose semantic elements for content'],
                        'canvas' => [['2001', 'crit_html', 'medium']],
                        'questions' => [
                            $this->mcq('Which element defines the most important (largest) heading?', ['<h6>', '<head>', '<h1>', '<header>'], 2, 'easy', 1),
                            $this->mcq('Which attribute gives an <img> alternative text for screen readers?', ['src', 'title', 'alt', 'longdesc'], 2, 'medium', 2),
                            $this->mcq('Which element should wrap a site navigation menu?', ['<div>', '<nav>', '<section>', '<menu>'], 1, 'medium', 2),
                            $this->mcq('Where should <meta charset="utf-8"> be placed?', ['Anywhere in <body>', 'Early inside <head>', 'After </html>', 'Inside <title>'], 1, 'hard', 3),
                        ],
                    ],
                    'CSS' => [
                        'description' => 'Selectors, the box model, specificity and layout.',
                        'outcomes' => ['Write selectors for elements, classes and ids', 'Explain the box model and specificity'],
                        'canvas' => [['2001', 'crit_css', 'medium']],
                        'questions' => [
                            $this->mcq('Which property changes the colour of text?', ['font-color', 'color', 'text-color', 'foreground'], 1, 'easy', 1),
                            $this->mcq('Which selector targets the element with id "main"?', ['.main', '#main', 'main', '*main'], 1, 'medium', 2),
                            $this->trueFalse('In the CSS box model, padding sits outside the border.', false, 'medium', 2),
                            $this->mcq('An id selector and two class selectors set the same property on one element. Which wins?', ['The id selector', 'The two classes', 'Whichever comes last', 'The browser decides randomly'], 0, 'hard', 3),
                        ],
                    ],
                    'JavaScript logic' => [
                        'description' => 'Comparisons, types, scope and control flow.',
                        'outcomes' => ['Predict the result of comparisons and type checks', 'Use block scope correctly'],
                        'canvas' => [['2002', '', 'medium', 'klenam']], // Klenam AI quiz published to Canvas
                        'questions' => [
                            $this->mcq("What does 3 === '3' evaluate to?", ['true', 'false', 'undefined', 'TypeError'], 1, 'easy', 1),
                            $this->mcq('What does console.log([1, 2, 3].length) print?', ['2', '3', '4', 'undefined'], 1, 'medium', 2),
                            $this->trueFalse('Variables declared with let are block-scoped.', true, 'medium', 2),
                            $this->mcq('What is typeof null?', ['"null"', '"undefined"', '"object"', '"number"'], 2, 'hard', 3),
                        ],
                    ],
                ],
            ],
            'PY101' => [
                'name' => 'Python Programming',
                'canvas_course_id' => '1002',
                'topics' => [
                    'Python basics' => [
                        'description' => 'Operators, types and code structure.',
                        'outcomes' => ['Predict results of arithmetic and type conversions', 'Read indentation-based blocks'],
                        'canvas' => [['3001', '', 'easy']],
                        'questions' => [
                            $this->mcq('What is the output of print(7 // 2)?', ['3.5', '3', '4', '3.0'], 1, 'easy', 1),
                            $this->mcq('Which of these types is immutable?', ['list', 'dict', 'set', 'tuple'], 3, 'medium', 2),
                            $this->trueFalse('Python uses indentation to define code blocks.', true, 'medium', 2),
                            $this->mcq("What does bool('False') return?", ['False', 'True', 'An error', 'None'], 1, 'hard', 3),
                        ],
                    ],
                    'Functions' => [
                        'description' => 'Defining functions, parameters and return values.',
                        'outcomes' => ['Define and call functions with parameters', 'Explain return values and default arguments'],
                        'canvas' => [['3002', '', 'hard']],
                        'questions' => [
                            $this->mcq('Which keyword defines a function?', ['func', 'def', 'function', 'lambda'], 1, 'easy', 1),
                            $this->mcq('What does a function return if it has no return statement?', ['0', 'None', 'False', 'An empty string'], 1, 'medium', 2),
                            $this->trueFalse('Default parameter values are evaluated once, when the function is defined.', true, 'medium', 2),
                            $this->mcq('What does f(*[1, 2]) do?', ['Passes the list as one argument', 'Unpacks the list into two positional arguments', 'Creates a tuple', 'Raises an error'], 1, 'hard', 3),
                        ],
                    ],
                    'Lists' => [
                        'description' => 'Indexing, methods and comprehensions.',
                        'outcomes' => ['Index and slice lists', 'Use list methods and comprehensions'],
                        'canvas' => [['3003', 'c_lists', 'medium']],
                        'questions' => [
                            $this->mcq('What is [1, 2, 3][-1]?', ['1', '3', '-1', 'IndexError'], 1, 'easy', 1),
                            $this->mcq('Which method adds one item to the end of a list?', ['add', 'append', 'extend', 'insert'], 1, 'medium', 2),
                            $this->trueFalse('list.sort() returns a new sorted list.', false, 'medium', 2),
                            $this->mcq('What is [x * 2 for x in range(3)]?', ['[0, 2, 4]', '[2, 4, 6]', '[0, 1, 2]', '[1, 2, 3]'], 0, 'hard', 3),
                        ],
                    ],
                    'Dictionaries' => [
                        'description' => 'Keys, values, lookups and merging.',
                        'outcomes' => ['Read and update dictionary values safely', 'Merge dictionaries'],
                        'canvas' => [['3003', 'c_dicts', 'hard']],
                        'questions' => [
                            $this->mcq("How do you get the value for key 'a' in dictionary d?", ['d.a', "d['a']", 'd(a)', 'd->a'], 1, 'easy', 1),
                            $this->mcq("What does d.get('x', 0) return if 'x' is missing?", ['None', 'KeyError', '0', "'x'"], 2, 'medium', 2),
                            $this->trueFalse('Dictionary keys must be hashable (for example, immutable).', true, 'medium', 2),
                            $this->mcq("What does {**a, **b} keep when both a and b have key 'k'?", ["a's value", "b's value", 'Both values in a list', 'It raises an error'], 1, 'hard', 3),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param list<string> $options */
    private function mcq(string $text, array $options, int $correctIndex, string $difficulty, int $marks): array
    {
        $ids = ['a', 'b', 'c', 'd'];
        $opts = [];
        foreach ($options as $i => $option) {
            $opts[] = ['id' => $ids[$i], 'text' => $option];
        }

        return [
            'question_type' => 'mcq',
            'question_text' => $text,
            'difficulty' => $difficulty,
            'max_marks' => number_format($marks, 2, '.', ''),
            'answer_data' => ['options' => $opts, 'correct_option_ids' => [$ids[$correctIndex]]],
            'explanation' => 'Correct answer: '.$options[$correctIndex].'.',
        ];
    }

    private function trueFalse(string $text, bool $answer, string $difficulty, int $marks): array
    {
        return [
            'question_type' => 'true_false',
            'question_text' => $text,
            'difficulty' => $difficulty,
            'max_marks' => number_format($marks, 2, '.', ''),
            'answer_data' => [
                'options' => [['id' => 't', 'text' => 'True'], ['id' => 'f', 'text' => 'False']],
                'correct_option_ids' => [$answer ? 't' : 'f'],
            ],
            'explanation' => 'The statement is '.($answer ? 'true' : 'false').'.',
        ];
    }
}
