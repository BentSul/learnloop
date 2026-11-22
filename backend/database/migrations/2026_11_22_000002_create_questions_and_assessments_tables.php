<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Question bank and LearnLoop practice/revision quizzes (spec v0.7 §3.2, §6.7; v0.8 §4.1, §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('question_type', 20);           // mcq | true_false | open_ended
            $table->text('question_text');
            $table->string('difficulty', 10)->default('medium'); // easy | medium | hard (D10 default)
            $table->decimal('max_marks', 6, 2);             // immutable once the question appears in an attempt (§3.2)
            // {"options":[{"id":"a","text":"..."}],"correct_option_ids":["a"]} — never returned to learners.
            $table->json('answer_data')->nullable();
            $table->text('explanation')->nullable();
            $table->string('source', 20)->default('learnloop'); // learnloop | klenam (v0.8 §4.1)
            $table->string('status', 20)->default('active');     // active | inactive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['topic_id', 'status']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('assessment_type', 20);       // practice | revision
            $table->foreignId('owner_learner_id')->nullable()->constrained('users')->cascadeOnDelete(); // revision only
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();                   // revision only
            $table->string('title');
            $table->string('status', 20)->default('open'); // draft | open | closed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['course_id', 'assessment_type', 'status']);
        });

        Schema::create('assessment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['assessment_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_items');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('questions');
    }
};
