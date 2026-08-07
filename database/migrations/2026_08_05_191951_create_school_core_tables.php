<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('official_key', 50)->nullable()->unique();
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();

            $table->string('street', 150)->nullable();
            $table->string('external_number', 20)->nullable();
            $table->string('internal_number', 20)->nullable();
            $table->string('neighborhood', 150)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('locality', 150)->nullable();
            $table->string('municipality', 150)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country_code', 2)->default('MX');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['is_active', 'name']);
            $table->index(['state', 'municipality', 'locality']);
        });

        Schema::create('school_cycles', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->foreignUuid('campus_id')
                ->constrained('campuses')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('name', 50);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('planned');
            $table->boolean('is_current')->default(false);
            $table->timestampsTz();

            $table->unique(['campus_id', 'name']);
            $table->index(['campus_id', 'status']);
            $table->index(['campus_id', 'is_current']);
            $table->index(['starts_on', 'ends_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE campuses
            ADD CONSTRAINT campuses_code_not_blank
            CHECK (btrim(code) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE campuses
            ADD CONSTRAINT campuses_name_not_blank
            CHECK (btrim(name) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE campuses
            ADD CONSTRAINT campuses_country_code_format
            CHECK (country_code ~ '^[A-Z]{2}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE school_cycles
            ADD CONSTRAINT school_cycles_valid_dates
            CHECK (ends_on >= starts_on)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE school_cycles
            ADD CONSTRAINT school_cycles_valid_status
            CHECK (status IN ('planned', 'active', 'closed', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX school_cycles_one_current_per_campus
            ON school_cycles (campus_id)
            WHERE is_current = true
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('school_cycles');
        Schema::dropIfExists('campuses');
    }
};