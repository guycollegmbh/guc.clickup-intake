<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_rules', function (Blueprint $table) {
            $table->id();
            // Ascending: the lowest value is checked first.
            $table->unsignedInteger('priority')->default(0);
            $table->string('match_type');
            $table->string('match_value');
            $table->string('clickup_list_id');
            // Denormalized "Space / Folder / List" for display without a ClickUp call.
            $table->string('clickup_list_label');
            $table->json('assignee_ids')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_rules');
    }
};
