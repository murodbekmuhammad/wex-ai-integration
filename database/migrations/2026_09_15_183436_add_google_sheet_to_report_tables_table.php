<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * The Google Sheet a table was uploaded to, so it can be opened again
     * instead of uploading a duplicate copy.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('report_tables', function (Blueprint $table) {
            $table->string('google_sheet_id')->nullable();
            $table->string('google_sheet_url')->nullable();
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('report_tables', function (Blueprint $table) {
            $table->dropColumn(['google_sheet_id', 'google_sheet_url']);
        });
    }
};
