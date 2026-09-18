<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * Local copy of the headers of the user's Gmail messages, so they can be
     * sorted and filtered quickly. Message bodies are fetched from Gmail on demand.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_id');
            $table->string('sender');
            $table->json('receivers');
            $table->text('subject');
            $table->text('snippet');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['user_id', 'gmail_id']);
            $table->index(['user_id', 'sent_at']);
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
