<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * The unexpected exception a failed run ended with, for tracking down
     * failures without the server log. Never shown to the user.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->text('error')->nullable()->after('summary');
        });
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('error');
        });
    }
};
