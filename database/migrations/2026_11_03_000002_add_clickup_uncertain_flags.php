<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An upload or comment whose outcome is unclear (connection lost, 503, 504) is checked against
 * ClickUp before it is sent again (E10). Separate migration: the previous one already ran in
 * the owner's local test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_attachments', function (Blueprint $table) {
            $table->boolean('upload_uncertain')->default(false)->after('upload_error');
        });

        Schema::table('intake_items', function (Blueprint $table) {
            $table->boolean('clickup_comment_uncertain')->default(false)->after('clickup_comment_added_at');
        });
    }

    public function down(): void
    {
        Schema::table('intake_attachments', function (Blueprint $table) {
            $table->dropColumn('upload_uncertain');
        });

        Schema::table('intake_items', function (Blueprint $table) {
            $table->dropColumn('clickup_comment_uncertain');
        });
    }
};
