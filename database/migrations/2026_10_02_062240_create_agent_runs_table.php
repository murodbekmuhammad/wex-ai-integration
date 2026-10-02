<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * The saved outcome of every agent run, listed under the agent on the
     * home page: its summary, the Google Sheet it wrote to and its steps.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // An agent key from config/agents.php.
            $table->string('agent_key');
            // "existing" updates the agent's Google Sheet, "new" creates another one.
            $table->string('sheet_mode');
            // completed, stopped or failed.
            $table->string('status');
            $table->text('summary')->nullable();
            $table->string('google_sheet_url')->nullable();
            // The steps the agent took, each with its tool, label, outcome and progress text.
            $table->json('steps');
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(['user_id', 'agent_key', 'created_at']);
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
