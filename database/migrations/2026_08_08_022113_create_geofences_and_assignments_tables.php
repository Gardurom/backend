<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('campus_id')
                ->constrained('campuses')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->string('name', 150);
            $table->text('description')->nullable();

            /*
             * polygon: área dibujada directamente.
             * circle: círculo generado desde centro y radio.
             * corridor: influencia alrededor de una ruta.
             */
            $table->string('type', 20);

            $table->decimal('radius_meters', 12, 2)->nullable();

            /*
             * Determina qué cambios generarán eventos.
             */
            $table->boolean('detect_entry')->default(true);
            $table->boolean('detect_exit')->default(true);

            /*
             * Configuración de horarios y días aplicables.
             */
            $table->jsonb('schedule')
                ->default(DB::raw("'{}'::jsonb"));

            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['campus_id', 'is_active']);
            $table->index(['type', 'is_active']);
            $table->index(['valid_from', 'valid_until']);
        });

        /*
         * effective_area es siempre MultiPolygon 4326.
         * center sólo se utiliza para geocercas circulares.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD COLUMN effective_area geometry(MultiPolygon, 4326)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD COLUMN center geography(Point, 4326)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX geofences_effective_area_gix
            ON geofences
            USING GIST (effective_area)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX geofences_center_gix
            ON geofences
            USING GIST (center)
        SQL);

        Schema::create(
            'geofence_student_assignments',
            function (Blueprint $table): void {
                $table->uuid('id')
                    ->primary()
                    ->default(DB::raw('uuidv7()'));

                $table->foreignUuid('geofence_id')
                    ->constrained('geofences')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();

                $table->foreignUuid('student_id')
                    ->constrained('students')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->foreignId('authorized_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();

                /*
                 * Referencia interna al consentimiento o autorización.
                 * No debe contener el documento completo.
                 */
                $table->string('consent_reference', 100)->nullable();
                $table->timestampTz('authorized_at')->nullable();

                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at')->nullable();

                $table->jsonb('notification_settings')
                    ->default(DB::raw("'{}'::jsonb"));

                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->index(['student_id', 'is_active']);
                $table->index(['geofence_id', 'is_active']);
                $table->index(['starts_at', 'ends_at']);
            }
        );

        /*
         * Sólo una asignación activa por alumno y geocerca.
         * Las asignaciones históricas inactivas se conservan.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX geofence_student_one_active_assignment
            ON geofence_student_assignments (
                geofence_id,
                student_id
            )
            WHERE is_active = true
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD CONSTRAINT geofences_name_not_blank
            CHECK (btrim(name) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD CONSTRAINT geofences_valid_type
            CHECK (
                type IN ('polygon', 'circle', 'corridor')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD CONSTRAINT geofences_valid_radius
            CHECK (
                radius_meters IS NULL
                OR (
                    radius_meters >= 1
                    AND radius_meters <= 100000
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD CONSTRAINT geofences_circle_consistency
            CHECK (
                type <> 'circle'
                OR (
                    center IS NOT NULL
                    AND radius_meters IS NOT NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD CONSTRAINT geofences_detection_enabled
            CHECK (
                detect_entry = true
                OR detect_exit = true
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofences
            ADD CONSTRAINT geofences_valid_dates
            CHECK (
                valid_until IS NULL
                OR valid_from IS NULL
                OR valid_until >= valid_from
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE geofence_student_assignments
            ADD CONSTRAINT geofence_assignments_valid_dates
            CHECK (
                ends_at IS NULL
                OR ends_at >= starts_at
            )
        SQL);

        /*
         * Una asignación activa requiere autorización documentada.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE geofence_student_assignments
            ADD CONSTRAINT geofence_assignments_authorization_required
            CHECK (
                is_active = false
                OR (
                    authorized_at IS NOT NULL
                    AND consent_reference IS NOT NULL
                    AND btrim(consent_reference) <> ''
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_student_assignments');
        Schema::dropIfExists('geofences');
    }
};