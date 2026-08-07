<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('localities', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->uuid('parent_id')->nullable();

            $table->string('official_code', 30)->unique();
            $table->string('name', 200);
            $table->string('type', 30);

            $table->unsignedBigInteger('population')->nullable();
            $table->decimal('area_square_km', 14, 4)->nullable();

            $table->jsonb('properties')
                ->default(DB::raw("'{}'::jsonb"));

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['parent_id', 'type']);
            $table->index(['type', 'is_active']);
            $table->index('name');
        });

        Schema::table('localities', function (Blueprint $table): void {
            $table->foreign('parent_id', 'localities_parent_id_foreign')
                ->references('id')
                ->on('localities')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        /*
         * Los tipos espaciales se crean mediante SQL nativo para no
         * depender todavía de un paquete geoespacial de Laravel.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE localities
            ADD COLUMN boundary geometry(MultiPolygon, 4326)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE localities
            ADD COLUMN representative_point geography(Point, 4326)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX localities_boundary_gix
            ON localities
            USING GIST (boundary)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX localities_representative_point_gix
            ON localities
            USING GIST (representative_point)
        SQL);

        Schema::create('student_addresses', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('student_id')
                ->constrained('students')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('locality_id')
                ->nullable()
                ->constrained('localities')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('street', 150)->nullable();
            $table->string('external_number', 20)->nullable();
            $table->string('internal_number', 20)->nullable();
            $table->string('neighborhood', 150)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->text('address_reference')->nullable();

            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->string('location_source', 30)->default('manual');

            $table->boolean('is_primary')->default(true);
            $table->boolean('is_verified')->default(false);

            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestampTz('verified_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['student_id', 'is_primary']);
            $table->index(['locality_id', 'is_verified']);
            $table->index(['postal_code', 'neighborhood']);
            $table->index(['valid_from', 'valid_until']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE student_addresses
            ADD COLUMN location geography(Point, 4326)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX student_addresses_location_gix
            ON student_addresses
            USING GIST (location)
        SQL);

        /*
         * Una sola dirección principal no eliminada por alumno.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX student_addresses_one_primary
            ON student_addresses (student_id)
            WHERE is_primary = true
              AND deleted_at IS NULL
        SQL);

        /*
         * Validaciones de localidades.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE localities
            ADD CONSTRAINT localities_code_not_blank
            CHECK (btrim(official_code) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE localities
            ADD CONSTRAINT localities_name_not_blank
            CHECK (btrim(name) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE localities
            ADD CONSTRAINT localities_valid_type
            CHECK (
                type IN (
                    'country',
                    'state',
                    'municipality',
                    'locality',
                    'neighborhood',
                    'school_zone',
                    'custom'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE localities
            ADD CONSTRAINT localities_valid_population
            CHECK (
                population IS NULL
                OR population >= 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE localities
            ADD CONSTRAINT localities_valid_area
            CHECK (
                area_square_km IS NULL
                OR area_square_km > 0
            )
        SQL);

        /*
         * Validaciones de domicilios.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE student_addresses
            ADD CONSTRAINT student_addresses_valid_accuracy
            CHECK (
                accuracy_meters IS NULL
                OR accuracy_meters >= 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE student_addresses
            ADD CONSTRAINT student_addresses_valid_source
            CHECK (
                location_source IN (
                    'manual',
                    'gps',
                    'geocoder',
                    'import',
                    'official_catalog'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE student_addresses
            ADD CONSTRAINT student_addresses_valid_dates
            CHECK (
                valid_until IS NULL
                OR valid_until >= valid_from
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE student_addresses
            ADD CONSTRAINT student_addresses_verification_consistency
            CHECK (
                (is_verified = true AND verified_at IS NOT NULL)
                OR
                (is_verified = false)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_addresses');
        Schema::dropIfExists('localities');
    }
};