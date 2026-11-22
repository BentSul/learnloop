<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klenam AI quizzes publish their scores to Canvas. A lecturer marks those Canvas items as
 * Klenam so their evidence joins the 'klenam' conflict group instead of 'canvas' (v0.8 D1-R).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canvas_item_mappings', function (Blueprint $table) {
            $table->string('source_group', 20)->default('canvas')->after('difficulty'); // canvas | klenam
        });

        Schema::table('external_result_items', function (Blueprint $table) {
            // Null on rows written before this column existed: use external_results.source.
            $table->string('source_group', 20)->nullable()->after('difficulty');
        });

        Schema::table('mapping_changes', function (Blueprint $table) {
            $table->string('old_source_group', 20)->nullable();
            $table->string('new_source_group', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mapping_changes', function (Blueprint $table) {
            $table->dropColumn(['old_source_group', 'new_source_group']);
        });
        Schema::table('external_result_items', function (Blueprint $table) {
            $table->dropColumn('source_group');
        });
        Schema::table('canvas_item_mappings', function (Blueprint $table) {
            $table->dropColumn('source_group');
        });
    }
};
