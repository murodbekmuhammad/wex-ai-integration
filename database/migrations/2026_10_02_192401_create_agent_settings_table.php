<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * How each user's agents run, saved on the Agent settings page.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('agent_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // "existing" updates the agent's Google Sheet, "new" creates another one on every run.
            $table->string('sheet_mode')->default('existing');
            // The invoice aging PDFs the agent may use; null means all of them.
            $table->json('pdf_document_ids')->nullable();
            $table->timestamps();
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_settings');
    }
};
