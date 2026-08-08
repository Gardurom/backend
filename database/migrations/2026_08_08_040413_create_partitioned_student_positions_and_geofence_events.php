<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Tabla particionada por captured_at.
         *
         * La clave primaria incluye captured_at porque PostgreSQL exige
         * incluir la clave de partición en restricciones únicas.
         */
        DB::statement(<<<'SQL'
            CREATE TABLE student_positions (
                id uuid NOT NULL DEFAULT uuidv7(),
                student_id uuid NOT NULL,
                captured_at timestamptz NOT NULL,
                received_at timestamptz NOT NULL DEFAULT now(),

                location geography(Point, 4326) NOT NULL,
                accuracy_meters numeric(10, 2),
                altitude_meters numeric(10, 2),
                speed_meters_second numeric(10, 3),
                heading_degrees numeric(6, 2),

                source varchar(30) NOT NULL DEFAULT 'gps',
                device_reference varchar(100),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,

                CONSTRAINT student_positions_primary
                    PRIMARY KEY (captured_at, id),

                CONSTRAINT student_positions_student_foreign
                    FOREIGN KEY (student_id)
                    REFERENCES students(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,

                CONSTRAINT student_positions_valid_accuracy
                    CHECK (
                        accuracy_meters IS NULL
                        OR accuracy_meters >= 0
                    ),

                CONSTRAINT student_positions_valid_speed
                    CHECK (
                        speed_meters_second IS NULL
                        OR speed_meters_second >= 0
                    ),

                CONSTRAINT student_positions_valid_heading
                    CHECK (
                        heading_degrees IS NULL
                        OR (
                            heading_degrees >= 0
                            AND heading_degrees < 360
                        )
                    ),

                CONSTRAINT student_positions_valid_source
                    CHECK (
                        source IN (
                            'gps',
                            'mobile',
                            'manual',
                            'import',
                            'device'
                        )
                    )
            )
            PARTITION BY RANGE (captured_at)
        SQL);

        /*
         * Particiones iniciales. La partición DEFAULT evita la caída del
         * sistema si todavía no se creó la partición de un mes futuro.
         */
        DB::statement(<<<'SQL'
            CREATE TABLE student_positions_2026_08
            PARTITION OF student_positions
            FOR VALUES FROM ('2026-08-01 00:00:00+00')
                       TO ('2026-09-01 00:00:00+00')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE student_positions_2026_09
            PARTITION OF student_positions
            FOR VALUES FROM ('2026-09-01 00:00:00+00')
                       TO ('2026-10-01 00:00:00+00')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE student_positions_2026_10
            PARTITION OF student_positions
            FOR VALUES FROM ('2026-10-01 00:00:00+00')
                       TO ('2026-11-01 00:00:00+00')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE student_positions_2026_11
            PARTITION OF student_positions
            FOR VALUES FROM ('2026-11-01 00:00:00+00')
                       TO ('2026-12-01 00:00:00+00')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE student_positions_2026_12
            PARTITION OF student_positions
            FOR VALUES FROM ('2026-12-01 00:00:00+00')
                       TO ('2027-01-01 00:00:00+00')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE student_positions_2027_01
            PARTITION OF student_positions
            FOR VALUES FROM ('2027-01-01 00:00:00+00')
                       TO ('2027-02-01 00:00:00+00')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE student_positions_default
            PARTITION OF student_positions DEFAULT
        SQL);

        /*
         * Estos índices particionados generan índices correspondientes
         * en cada partición.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX student_positions_location_gix
            ON student_positions
            USING GIST (location)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX student_positions_student_captured_index
            ON student_positions (student_id, captured_at DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX student_positions_captured_brin
            ON student_positions
            USING BRIN (captured_at)
        SQL);

        /*
         * Estado actual por combinación alumno-geocerca.
         * Permite detectar cambios sin recorrer todo el historial.
         */
        DB::statement(<<<'SQL'
            CREATE TABLE geofence_student_states (
                geofence_id uuid NOT NULL,
                student_id uuid NOT NULL,

                is_inside boolean NOT NULL,
                last_position_id uuid,
                last_position_captured_at timestamptz,
                state_changed_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT geofence_student_states_primary
                    PRIMARY KEY (geofence_id, student_id),

                CONSTRAINT geofence_student_states_geofence_foreign
                    FOREIGN KEY (geofence_id)
                    REFERENCES geofences(id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,

                CONSTRAINT geofence_student_states_student_foreign
                    FOREIGN KEY (student_id)
                    REFERENCES students(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX geofence_student_states_student_index
            ON geofence_student_states (student_id)
        SQL);

        /*
         * Eventos conservados como historial de auditoría.
         * La ubicación se copia para conservar evidencia aunque las
         * posiciones detalladas se archiven posteriormente.
         */
        DB::statement(<<<'SQL'
            CREATE TABLE geofence_events (
                id uuid PRIMARY KEY DEFAULT uuidv7(),

                geofence_id uuid NOT NULL,
                student_id uuid NOT NULL,
                assignment_id uuid,

                position_id uuid NOT NULL,
                position_captured_at timestamptz NOT NULL,

                event_type varchar(20) NOT NULL,
                occurred_at timestamptz NOT NULL,
                detected_at timestamptz NOT NULL DEFAULT now(),

                location geography(Point, 4326) NOT NULL,
                payload jsonb NOT NULL DEFAULT '{}'::jsonb,

                created_at timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT geofence_events_geofence_foreign
                    FOREIGN KEY (geofence_id)
                    REFERENCES geofences(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,

                CONSTRAINT geofence_events_student_foreign
                    FOREIGN KEY (student_id)
                    REFERENCES students(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,

                CONSTRAINT geofence_events_assignment_foreign
                    FOREIGN KEY (assignment_id)
                    REFERENCES geofence_student_assignments(id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL,

                CONSTRAINT geofence_events_valid_type
                    CHECK (event_type IN ('entry', 'exit')),

                CONSTRAINT geofence_events_position_unique
                    UNIQUE (
                        geofence_id,
                        student_id,
                        event_type,
                        position_id
                    )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX geofence_events_location_gix
            ON geofence_events
            USING GIST (location)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX geofence_events_student_occurred_index
            ON geofence_events (student_id, occurred_at DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX geofence_events_geofence_occurred_index
            ON geofence_events (geofence_id, occurred_at DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX geofence_events_occurred_brin
            ON geofence_events
            USING BRIN (occurred_at)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS geofence_events');
        DB::statement('DROP TABLE IF EXISTS geofence_student_states');
        DB::statement(
            'DROP TABLE IF EXISTS student_positions CASCADE'
        );
    }
};