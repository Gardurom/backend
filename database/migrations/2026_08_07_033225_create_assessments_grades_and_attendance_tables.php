<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_periods', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('school_cycle_id')
                ->constrained('school_cycles')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('name', 100);
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('planned');

            $table->timestampsTz();

            $table->unique(
                ['school_cycle_id', 'sequence'],
                'grading_periods_cycle_sequence_unique'
            );

            $table->unique(
                ['school_cycle_id', 'name'],
                'grading_periods_cycle_name_unique'
            );

            $table->index(['school_cycle_id', 'status']);
        });

        Schema::create('assessments', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('teaching_assignment_id')
                ->constrained('teaching_assignments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('grading_period_id')
                ->constrained('grading_periods')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('type', 30);
            $table->decimal('maximum_score', 7, 2)->default(100);
            $table->decimal('weight', 5, 2);
            $table->timestampTz('due_at')->nullable();
            $table->string('status', 20)->default('draft');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(
                [
                    'teaching_assignment_id',
                    'grading_period_id',
                    'name',
                ],
                'assessments_assignment_period_name_unique'
            );

            $table->index(
                ['teaching_assignment_id', 'status'],
                'assessments_assignment_status_index'
            );

            $table->index(
                ['grading_period_id', 'status'],
                'assessments_period_status_index'
            );
        });

        Schema::create('grades', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('assessment_id')
                ->constrained('assessments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('graded_by_teacher_id')
                ->nullable()
                ->constrained('teachers')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->decimal('score', 7, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestampTz('graded_at')->nullable();
            $table->string('status', 20)->default('pending');

            $table->timestampsTz();

            $table->unique(
                ['assessment_id', 'enrollment_id'],
                'grades_assessment_enrollment_unique'
            );

            $table->index(['enrollment_id', 'status']);
            $table->index(['assessment_id', 'status']);
            $table->index('graded_at');
        });

        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('school_group_id')
                ->constrained('school_groups')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('teaching_assignment_id')
                ->nullable()
                ->constrained('teaching_assignments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('recorded_by_teacher_id')
                ->nullable()
                ->constrained('teachers')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->date('held_on');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('type', 20)->default('daily');
            $table->string('status', 20)->default('scheduled');
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(['school_group_id', 'held_on']);
            $table->index(['teaching_assignment_id', 'held_on']);
            $table->index(['held_on', 'status']);
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('attendance_session_id')
                ->constrained('attendance_sessions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            /*
             * Se utiliza enrollment_id en vez de student_id para conservar
             * el grupo y ciclo escolar históricos del alumno.
             */
            $table->foreignUuid('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('status', 20);
            $table->unsignedSmallInteger('minutes_late')->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('recorded_at');

            $table->timestampsTz();

            $table->unique(
                ['attendance_session_id', 'enrollment_id'],
                'attendance_records_session_enrollment_unique'
            );

            $table->index(['enrollment_id', 'status']);
            $table->index(['recorded_at', 'status']);
        });

        /*
         * Periodos de calificación.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE grading_periods
            ADD CONSTRAINT grading_periods_name_not_blank
            CHECK (btrim(name) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE grading_periods
            ADD CONSTRAINT grading_periods_valid_sequence
            CHECK (sequence BETWEEN 1 AND 20)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE grading_periods
            ADD CONSTRAINT grading_periods_valid_dates
            CHECK (ends_on >= starts_on)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE grading_periods
            ADD CONSTRAINT grading_periods_valid_status
            CHECK (
                status IN ('planned', 'active', 'closed', 'cancelled')
            )
        SQL);

        /*
         * Evaluaciones.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE assessments
            ADD CONSTRAINT assessments_name_not_blank
            CHECK (btrim(name) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assessments
            ADD CONSTRAINT assessments_valid_type
            CHECK (
                type IN (
                    'exam',
                    'quiz',
                    'homework',
                    'project',
                    'participation',
                    'practice',
                    'other'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assessments
            ADD CONSTRAINT assessments_valid_maximum_score
            CHECK (maximum_score > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assessments
            ADD CONSTRAINT assessments_valid_weight
            CHECK (weight > 0 AND weight <= 100)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assessments
            ADD CONSTRAINT assessments_valid_status
            CHECK (
                status IN ('draft', 'published', 'closed', 'cancelled')
            )
        SQL);

        /*
         * Calificaciones.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE grades
            ADD CONSTRAINT grades_nonnegative_score
            CHECK (score IS NULL OR score >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE grades
            ADD CONSTRAINT grades_valid_status
            CHECK (
                status IN ('pending', 'graded', 'exempt', 'cancelled')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE grades
            ADD CONSTRAINT grades_status_score_consistency
            CHECK (
                (status = 'graded' AND score IS NOT NULL)
                OR
                (status <> 'graded' AND score IS NULL)
            )
        SQL);

        /*
         * Sesiones de asistencia.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE attendance_sessions
            ADD CONSTRAINT attendance_sessions_valid_type
            CHECK (type IN ('daily', 'class'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_sessions
            ADD CONSTRAINT attendance_sessions_valid_status
            CHECK (
                status IN ('scheduled', 'open', 'closed', 'cancelled')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attendance_sessions
            ADD CONSTRAINT attendance_sessions_valid_times
            CHECK (
                ends_at IS NULL
                OR starts_at IS NULL
                OR ends_at > starts_at
            )
        SQL);

        /*
         * Evita sesiones duplicadas aunque teaching_assignment_id
         * o starts_at sean NULL.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attendance_sessions_identity_unique
            ON attendance_sessions (
                school_group_id,
                COALESCE(
                    teaching_assignment_id,
                    '00000000-0000-0000-0000-000000000000'::uuid
                ),
                held_on,
                COALESCE(starts_at, '00:00:00'::time)
            )
        SQL);

        /*
         * Registros individuales de asistencia.
         */
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
                (status = 'late' AND minutes_late > 0)
                OR
                (status <> 'late' AND minutes_late = 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('grading_periods');
    }
};