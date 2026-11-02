<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_attachments', function (Blueprint $table) {
            $table->id();
            // Rows go with the item, files with the item's model event (R8).
            $table->foreignId('intake_item_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('file');
            $table->string('graph_attachment_id');
            $table->string('name');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('content_id')->nullable();
            $table->boolean('is_inline')->default(false);
            // Set only after the file is completely written (R8).
            $table->string('storage_path')->nullable();
            // Storage errors only (download, size limit). Never set for ClickUp uploads.
            $table->text('error')->nullable();

            // Triage selection and ClickUp upload result (steps 4 and 5).
            $table->boolean('selected_for_upload')->default(false);
            $table->boolean('uploaded_to_clickup')->default(false);
            $table->text('upload_error')->nullable();

            $table->timestamps();

            // One row per Graph attachment and item, also the index for the foreign key.
            $table->unique(['intake_item_id', 'graph_attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_attachments');
    }
};
