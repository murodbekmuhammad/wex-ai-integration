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
     * The bare address from the From header, so the inbox can be filtered by
     * sender without parsing the full header on every query.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('sender_email')->default('')->after('sender');

            $table->index(['user_id', 'sender_email']);
        });

        $this->backfill();
    }

    /**
     * down
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'sender_email']);
            $table->dropColumn('sender_email');
        });
    }

    /**
     * backfill
     *
     * Fill the new column for rows that were synced before it existed.
     *
     * @return void
     */
    private function backfill(): void
    {
        DB::table('emails')->select('id', 'sender')->orderBy('id')->chunk(500, function ($emails) {
            foreach ($emails as $email) {
                preg_match('/[\w.+\'-]+@[\w-]+(?:\.[\w-]+)+/', (string) $email->sender, $matches);

                DB::table('emails')->where('id', $email->id)->update([
                    'sender_email' => strtolower($matches[0] ?? ''),
                ]);
            }
        });
    }
};
