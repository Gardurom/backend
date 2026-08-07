<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('paternal_surname', 100);
            $table->string('maternal_surname', 100)->nullable();

            $table->string('curp', 18)->nullable()->unique();
            $table->date('birth_date')->nullable();
            $table->string('sex', 20)->nullable();

            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('emergency_phone', 30)->nullable();

            $table->jsonb('additional_data')->default(DB::raw("'{}'::jsonb"));

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index([
                'paternal_surname',
                'maternal_surname',
                'first_name',
            ], 'people_name_index');

            $table->index('birth_date');
            $table->index('email');
        });

        Schema::create('students', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('person_id')
                ->unique()
                ->constrained('people')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('campus_id')
                ->constrained('campuses')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('enrollment_number', 50);
            $table->date('enrolled_on');
            $table->date('withdrawn_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(
                ['campus_id', 'enrollment_number'],
                'students_campus_enrollment_unique'
            );

            $table->index(['campus_id', 'status']);
            $table->index(['campus_id', 'enrolled_on']);
        });

        Schema::create('teachers', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('person_id')
                ->unique()
                ->constrained('people')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('campus_id')
                ->constrained('campuses')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('employee_number', 50);
            $table->string('professional_license', 50)->nullable();
            $table->date('hired_on')->nullable();
            $table->date('terminated_on')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(
                ['campus_id', 'employee_number'],
                'teachers_campus_employee_unique'
            );

            $table->index(['campus_id', 'status']);
            $table->index('professional_license');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE people
            ADD CONSTRAINT people_first_name_not_blank
            CHECK (btrim(first_name) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE people
            ADD CONSTRAINT people_paternal_surname_not_blank
            CHECK (btrim(paternal_surname) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE people
            ADD CONSTRAINT people_curp_format
            CHECK (
                curp IS NULL
                OR curp ~ '^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9][0-9]$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE people
            ADD CONSTRAINT people_valid_sex
            CHECK (
                sex IS NULL
                OR sex IN ('female', 'male', 'unspecified')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE people
            ADD CONSTRAINT people_birth_date_not_future
            CHECK (
                birth_date IS NULL
                OR birth_date <= CURRENT_DATE
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE students
            ADD CONSTRAINT students_enrollment_not_blank
            CHECK (btrim(enrollment_number) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE students
            ADD CONSTRAINT students_valid_status
            CHECK (
                status IN (
                    'applicant',
                    'active',
                    'inactive',
                    'graduated',
                    'withdrawn',
                    'suspended'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE students
            ADD CONSTRAINT students_valid_dates
            CHECK (
                withdrawn_on IS NULL
                OR withdrawn_on >= enrolled_on
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE teachers
            ADD CONSTRAINT teachers_employee_number_not_blank
            CHECK (btrim(employee_number) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE teachers
            ADD CONSTRAINT teachers_valid_status
            CHECK (
                status IN (
                    'active',
                    'inactive',
                    'leave',
                    'terminated'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE teachers
            ADD CONSTRAINT teachers_valid_dates
            CHECK (
                terminated_on IS NULL
                OR hired_on IS NULL
                OR terminated_on >= hired_on
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('students');
        Schema::dropIfExists('people');
    }
};