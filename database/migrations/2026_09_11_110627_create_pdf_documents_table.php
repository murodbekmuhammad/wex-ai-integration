<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * PDF attachments collected from Gmail. The file itself lives on the local
     * disk; this table records where it came from so it can be filtered by
     * sender and handed to Claude for analysis.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('pdf_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_id');
            // MIME part id of the attachment inside the message, e.g. "1.2".
            $table->string('part_id');
            $table->string('filename');
            $table->string('sender');
            $table->string('sender_email');
            $table->text('subject');
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['user_id', 'gmail_id', 'part_id']);
            $table->index(['user_id', 'sender_email']);
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_documents');
    }
};
