<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('person_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained('people')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->boolean('is_active')
                ->default(true)
                ->after('password');

            $table->unsignedSmallInteger('failed_login_attempts')
                ->default(0)
                ->after('is_active');

            $table->timestampTz('locked_until')
                ->nullable()
                ->after('failed_login_attempts');

            $table->timestampTz('last_login_at')
                ->nullable()
                ->after('locked_until');

            $table->timestampTz('password_changed_at')
                ->nullable()
                ->after('last_login_at');

            $table->boolean('must_change_password')
                ->default(false)
                ->after('password_changed_at');

            $table->boolean('mfa_enabled')
                ->default(false)
                ->after('must_change_password');

            $table->index(['is_active', 'locked_until']);
        });

        /*
         * PostgreSQL dispone del tipo inet para validar y almacenar
         * IPv4 o IPv6 sin tratarlas como texto arbitrario.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD COLUMN last_login_ip inet
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_failed_login_attempts_limit
            CHECK (failed_login_attempts <= 100)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE users
            DROP CONSTRAINT IF EXISTS users_failed_login_attempts_limit
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
            DROP COLUMN IF EXISTS last_login_ip
        SQL);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['person_id']);
            $table->dropUnique(['person_id']);
            $table->dropIndex(['is_active', 'locked_until']);

            $table->dropColumn([
                'person_id',
                'is_active',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'password_changed_at',
                'must_change_password',
                'mfa_enabled',
            ]);
        });
    }
};