<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('entra_object_id')->unique();
            $table->string('mailbox_upn');
            $table->string('role')->default('member');
            $table->boolean('active')->default(true);

            // Microsoft 365 (delegated, see docs/01-decisions.md E1).
            $table->text('ms_refresh_token')->nullable();
            $table->string('ms_connection_status')->default('disconnected');
            $table->timestamp('ms_connected_at')->nullable();

            // ClickUp (OAuth per user, see docs/01-decisions.md E2).
            $table->text('clickup_token')->nullable();
            $table->string('clickup_connection_status')->default('disconnected');
            $table->string('clickup_member_id')->nullable();
            $table->string('last_clickup_list_id')->nullable();

            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
