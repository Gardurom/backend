<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('campus_id')
                ->constrained('campuses')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('weekly_hours', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(
                ['campus_id', 'code'],
                'subjects_campus_code_unique'
            );

            $table->index(
                ['campus_id', 'is_active', 'name'],
                'subjects_campus_active_name_index'
            );
        });

        Schema::create('school_groups', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('school_cycle_id')
                ->constrained('school_cycles')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('grade_level', 30);
            $table->string('section', 20);
            $table->string('shift', 20);
            $table->unsignedSmallInteger('capacity')->default(40);
            $table->string('classroom', 50)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(
                [
                    'school_cycle_id',
                    'grade_level',
                    'section',
                    'shift',
                ],
                'school_groups_identity_unique'
            );

            /*
             * Esta restricción permite que enrollments compruebe
             * que el grupo realmente pertenece al ciclo indicado.
             */
            $table->unique(
                ['id', 'school_cycle_id'],
                'school_groups_id_cycle_unique'
            );

            $table->index(
                ['school_cycle_id', 'is_active'],
                'school_groups_cycle_active_index'
            );
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('student_id')
                ->constrained('students')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->uuid('school_cycle_id');
            $table->uuid('school_group_id');

            $table->date('enrolled_on');
            $table->date('withdrawn_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            /*
             * Llave foránea compuesta:
             * evita asociar una inscripción con un grupo de otro ciclo.
             */
            $table->foreign(
                ['school_group_id', 'school_cycle_id'],
                'enrollments_group_cycle_foreign'
            )
                ->references(['id', 'school_cycle_id'])
                ->on('school_groups')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            /*
             * Un alumno sólo puede estar inscrito en un grupo
             * durante el mismo ciclo escolar.
             */
            $table->unique(
                ['student_id', 'school_cycle_id'],
                'enrollments_student_cycle_unique'
            );

            $table->index(
                ['school_group_id', 'status'],
                'enrollments_group_status_index'
            );

            $table->index(
                ['school_cycle_id', 'status'],
                'enrollments_cycle_status_index'
            );
        });

        Schema::create('teaching_assignments', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('school_group_id')
                ->constrained('school_groups')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('teacher_id')
                ->constrained('teachers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(
                ['school_group_id', 'subject_id', 'teacher_id'],
                'teaching_assignments_unique'
            );

            $table->index(
                ['teacher_id', 'status'],
                'teaching_assignments_teacher_status_index'
            );

            $table->index(
                ['school_group_id', 'subject_id'],
                'teaching_assignments_group_subject_index'
            );
        });

        /*
         * Restricciones de materias.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE subjects
            ADD CONSTRAINT subjects_code_not_blank
            CHECK (btrim(code) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE subjects
            ADD CONSTRAINT subjects_name_not_blank
            CHECK (btrim(name) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE subjects
            ADD CONSTRAINT subjects_valid_weekly_hours
            CHECK (
                weekly_hours IS NULL
                OR weekly_hours > 0
            )
        SQL);

        /*
         * Restricciones de grupos.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE school_groups
            ADD CONSTRAINT school_groups_grade_not_blank
            CHECK (btrim(grade_level) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE school_groups
            ADD CONSTRAINT school_groups_section_not_blank
            CHECK (btrim(section) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE school_groups
            ADD CONSTRAINT school_groups_valid_shift
            CHECK (
                shift IN (
                    'morning',
                    'afternoon',
                    'evening',
                    'full_time'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE school_groups
            ADD CONSTRAINT school_groups_valid_capacity
            CHECK (capacity BETWEEN 1 AND 500)
        SQL);

        /*
         * Restricciones de inscripciones.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE enrollments
            ADD CONSTRAINT enrollments_valid_status
            CHECK (
                status IN (
                    'active',
                    'completed',
                    'withdrawn',
                    'cancelled'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE enrollments
            ADD CONSTRAINT enrollments_valid_dates
            CHECK (
                withdrawn_on IS NULL
                OR withdrawn_on >= enrolled_on
            )
        SQL);

        /*
         * Restricciones de asignaciones docentes.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE teaching_assignments
            ADD CONSTRAINT teaching_assignments_valid_status
            CHECK (
                status IN (
                    'active',
                    'completed',
                    'cancelled'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE teaching_assignments
            ADD CONSTRAINT teaching_assignments_valid_dates
            CHECK (
                ends_on IS NULL
                OR starts_on IS NULL
                OR ends_on >= starts_on
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('school_groups');
        Schema::dropIfExists('subjects');
    }
};