<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_items', function (Blueprint $table) {
            // Failed imports are retried with a growing wait (E8).
            $table->unsignedInteger('import_attempts')->default(0);
            $table->timestamp('next_import_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('intake_items', function (Blueprint $table) {
            $table->dropColumn(['import_attempts', 'next_import_at']);
        });
    }
};
