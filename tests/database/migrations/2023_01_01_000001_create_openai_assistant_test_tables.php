<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_assistants')) {
            Schema::create('ai_assistants', function (Blueprint $table) {
                $table->id();
                $table->string('openai_assistant_id')->nullable();
                $table->string('name');
                $table->text('instructions')->nullable();
                $table->string('engine')->default('gpt-3.5-turbo');
                $table->string('vector_store_id')->nullable();
                $table->json('tools')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assistant_id')->constrained('ai_assistants');
                $table->string('openai_thread_id')->nullable();
                $table->string('uuid')->unique();
                $table->morphs('model');
                $table->json('metadata')->nullable();
                $table->string('status')->default('created');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('thread_id')->constrained('ai_threads');
                $table->foreignId('assistant_id')->nullable()->constrained('ai_assistants');
                $table->string('openai_message_id')->nullable();
                $table->string('openai_run_id')->nullable();
                $table->string('role')->default('user');
                $table->text('content')->nullable();
                $table->text('prompt')->nullable();
                $table->text('response')->nullable();
                $table->string('response_type')->default('text');
                $table->string('run_status')->nullable();
                $table->json('file_ids')->nullable();
                $table->nullableMorphs('userable');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ai_files')) {
            Schema::create('ai_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assistant_id')->constrained('ai_assistants');
                $table->foreignId('thread_id')->constrained('ai_threads');
                $table->string('openai_file_id');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_files');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');
        Schema::dropIfExists('ai_assistants');
    }
}; 