<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up
     *
     * Each agent gets its own settings, keyed by its config/agents.php key.
     * Settings saved before this belong to the invoice aging agent.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->string('agent_key')->default('invoice_aging')->after('user_id');
            $table->unique(['user_id', 'agent_key']);
            $table->dropUnique(['user_id']);
        });
    }

    /**
     * down
     *
     * Keeps only the invoice aging agent's settings, one row per user.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('agent_settings')->where('agent_key', '!=', 'invoice_aging')->delete();

        Schema::table('agent_settings', function (Blueprint $table) {
            $table->unique('user_id');
            $table->dropUnique(['user_id', 'agent_key']);
            $table->dropColumn('agent_key');
        });
    }
};
