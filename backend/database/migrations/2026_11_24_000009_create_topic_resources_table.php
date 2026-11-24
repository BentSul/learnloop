<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning resources for a topic (videos, readings, practice sites) that students can use to
 * study on their own. Lecturers add them; YouTube suggestions wait as "suggested" until a
 * lecturer approves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                     // video | reading | practice | other
            $table->string('title', 200);
            $table->string('url', 2048);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('published'); // published | suggested
            $table->string('source', 20)->default('lecturer');  // lecturer | youtube
            $table->string('external_id', 64)->nullable();      // e.g. YouTube video ID, to avoid duplicates
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['topic_id', 'status', 'position']);
            $table->unique(['topic_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_resources');
    }
};
