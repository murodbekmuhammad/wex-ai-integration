<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * Tables Claude built from collected PDFs at the user's request, kept so
     * they can be shown again, revised and downloaded as Excel or PDF.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('report_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // What the user asked for, so the table's origin is clear later.
            $table->text('request');
            $table->text('summary');
            $table->json('columns');
            $table->json('rows');
            // Problems the app found while checking Claude's numbers, e.g. a total that doesn't add up.
            $table->json('warnings');
            // The PDFs Claude read, reused when the table is revised.
            $table->json('pdf_document_ids');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('report_tables');
    }
};
