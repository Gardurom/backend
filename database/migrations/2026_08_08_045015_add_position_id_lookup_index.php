<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX student_positions_id_lookup_index
            ON student_positions (id)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DROP INDEX IF EXISTS student_positions_id_lookup_index
        SQL);
    }
};