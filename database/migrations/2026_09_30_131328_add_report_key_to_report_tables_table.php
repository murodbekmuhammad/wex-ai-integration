<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * Which recurring report a table is, e.g. "invoice_aging", so the next
     * run can update the same Google Sheet instead of creating another.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('report_tables', function (Blueprint $table) {
            $table->string('report_key')->nullable();
            $table->index(['user_id', 'report_key']);
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
            $table->dropIndex(['user_id', 'report_key']);
            $table->dropColumn('report_key');
        });
    }
};
