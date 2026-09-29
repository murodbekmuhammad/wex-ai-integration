<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * The report type from config/report_types.php whose columns Claude found
     * in each PDF, if any. classified_at is null until Claude has read the file.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->string('report_type')->nullable()->after('path');
            $table->timestamp('classified_at')->nullable()->after('report_type');

            $table->index(['user_id', 'report_type']);
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'report_type']);
            $table->dropColumn(['report_type', 'classified_at']);
        });
    }
};
