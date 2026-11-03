<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The comment "Absender und Mail-Link" is added once per task (step 5, E10). The wish is kept
 * on the item, so "Fehlende Anhänge erneut hochladen" adds a comment that failed before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_items', function (Blueprint $table) {
            $table->boolean('clickup_comment_requested')->default(false)->after('clickup_list_id');
            $table->timestamp('clickup_comment_added_at')->nullable()->after('clickup_comment_requested');
        });
    }

    public function down(): void
    {
        Schema::table('intake_items', function (Blueprint $table) {
            $table->dropColumn(['clickup_comment_requested', 'clickup_comment_added_at']);
        });
    }
};
