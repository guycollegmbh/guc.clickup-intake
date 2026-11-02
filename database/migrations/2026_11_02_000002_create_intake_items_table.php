<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_items', function (Blueprint $table) {
            $table->id();

            // Restrict: items (and their files) must never disappear with a user (R6, R8).
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Display only, the mailbox is always the token owner's (E6).
            $table->string('mailbox_upn');

            // Changes on move, reference only (R7).
            $table->string('graph_message_id');
            $table->string('internet_message_id');

            $table->text('subject')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->json('to_json')->nullable();
            $table->json('cc_json')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('web_link')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();

            $table->string('status')->default('pending');
            $table->string('clickup_task_id')->nullable();
            $table->string('clickup_task_url')->nullable();
            // Target list of the created task, not the pre-selection (that comes from rules or
            // users.last_clickup_list_id at triage time).
            $table->string('clickup_list_id')->nullable();

            $table->text('error')->nullable();
            // Set only once the item and all its attachments are completely imported (step 3).
            // Items without it are not shown in triage.
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            // Set only after a successful Outlook category update (R10), reset to null on every
            // status change. Retention requires it (E7).
            $table->timestamp('outlook_category_synced_at')->nullable();

            $table->timestamps();

            // Dedupe (R7, E6).
            $table->unique(['user_id', 'internet_message_id']);
            // Inbox list per user and status.
            $table->index(['user_id', 'status']);
            // Retention.
            $table->index(['status', 'processed_at']);
            $table->index('imported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_items');
    }
};
