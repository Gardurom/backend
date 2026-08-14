<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            DROP CONSTRAINT IF EXISTS
                attendance_records_valid_minutes_late
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            DROP CONSTRAINT IF EXISTS
                attendance_records_valid_status
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ALTER COLUMN recorded_at DROP NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ALTER COLUMN status SET DEFAULT 'pending'
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ADD CONSTRAINT attendance_records_valid_status
            CHECK (
                status IN (
                    'pending',
                    'present',
                    'absent',
                    'late',
                    'excused'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ADD CONSTRAINT attendance_records_valid_minutes_late
            CHECK (
                (
                    status = 'late'
                    AND minutes_late > 0
                )
                OR
                (
                    status <> 'late'
                    AND minutes_late = 0
                )
            )
        SQL);
    }

    public function down(): void
    {
        /*
         * PostgreSQL no puede restaurar NOT NULL mientras existan
         * filas pendientes sin fecha. El rollback las convierte
         * en ausencias antes de restaurar el esquema anterior.
         */
        DB::statement(<<<'SQL'
            UPDATE attendance_records
            SET
                status = 'absent',
                minutes_late = 0,
                recorded_at = COALESCE(
                    recorded_at,
                    updated_at,
                    created_at,
                    CURRENT_TIMESTAMP
                )
            WHERE status = 'pending'
               OR recorded_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            DROP CONSTRAINT IF EXISTS
                attendance_records_valid_minutes_late
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            DROP CONSTRAINT IF EXISTS
                attendance_records_valid_status
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ALTER COLUMN status DROP DEFAULT
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ALTER COLUMN recorded_at SET NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ADD CONSTRAINT attendance_records_valid_status
            CHECK (
                status IN (
                    'present',
                    'absent',
                    'late',
                    'excused'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_records
            ADD CONSTRAINT attendance_records_valid_minutes_late
            CHECK (
                (
                    status = 'late'
                    AND minutes_late > 0
                )
                OR
                (
                    status <> 'late'
                    AND minutes_late = 0
                )
            )
        SQL);
    }
};