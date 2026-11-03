<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate migration: the users table already exists in installed environments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Denormalized "Space / Folder / List" for display without a ClickUp call.
            $table->string('last_clickup_list_label')->nullable()->after('last_clickup_list_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_clickup_list_label');
        });
    }
};
