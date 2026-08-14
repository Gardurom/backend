<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Optimiza filtros como:
         *
         * WHERE status = 'open'
         * ORDER BY held_on DESC
         */
        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS
    attendance_sessions_status_held_on_idx
ON attendance_sessions (
    status,
    held_on DESC
)
SQL);

        /*
         * Optimiza el historial de sesiones registradas
         * por un profesor.
         *
         * El índice parcial evita almacenar entradas cuyo
         * profesor sea NULL.
         */
        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS
    attendance_sessions_teacher_held_on_idx
ON attendance_sessions (
    recorded_by_teacher_id,
    held_on DESC
)
WHERE recorded_by_teacher_id IS NOT NULL
SQL);

        /*
         * Optimiza los conteos y filtros utilizados por:
         *
         * pending_records_count
         * present_records_count
         * absent_records_count
         * late_records_count
         * excused_records_count
         */
        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS
    attendance_records_session_status_idx
ON attendance_records (
    attendance_session_id,
    status
)
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
DROP INDEX IF EXISTS
    attendance_records_session_status_idx
SQL);

        DB::statement(<<<'SQL'
DROP INDEX IF EXISTS
    attendance_sessions_teacher_held_on_idx
SQL);

        DB::statement(<<<'SQL'
DROP INDEX IF EXISTS
    attendance_sessions_status_held_on_idx
SQL);
    }
};