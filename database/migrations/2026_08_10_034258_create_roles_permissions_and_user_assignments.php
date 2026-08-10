<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->string('name', 80)->unique();
            $table->string('display_name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')
                ->primary()
                ->default(DB::raw('uuidv7()'));

            $table->string('name', 120)->unique();
            $table->string('module', 80);
            $table->string('action', 50);
            $table->string('display_name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['module', 'action']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('role_id')
                ->constrained('roles')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->timestampTz('created_at')->useCurrent();

            $table->primary(
                ['role_id', 'permission_id'],
                'role_permissions_primary'
            );
        });

        Schema::create(
            'user_role_assignments',
            function (Blueprint $table): void {
                $table->uuid('id')
                    ->primary()
                    ->default(DB::raw('uuidv7()'));

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();

                $table->foreignUuid('role_id')
                    ->constrained('roles')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                /*
                 * NULL representa un rol global, como superadministrador.
                 * Los demás roles normalmente tendrán un campus.
                 */
                $table->foreignUuid('campus_id')
                    ->nullable()
                    ->constrained('campuses')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->foreignId('assigned_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();

                $table->timestampTz('starts_at')->useCurrent();
                $table->timestampTz('ends_at')->nullable();
                $table->boolean('is_active')->default(true);

                $table->timestampsTz();

                $table->index(['user_id', 'is_active']);
                $table->index(['campus_id', 'is_active']);
                $table->index(['role_id', 'is_active']);
                $table->index(['starts_at', 'ends_at']);
            }
        );

        /*
         * Impide dos asignaciones activas equivalentes, incluyendo
         * roles globales donde campus_id es NULL.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_role_one_active_assignment
            ON user_role_assignments (
                user_id,
                role_id,
                COALESCE(
                    campus_id,
                    '00000000-0000-0000-0000-000000000000'::uuid
                )
            )
            WHERE is_active = true
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE roles
            ADD CONSTRAINT roles_name_format
            CHECK (
                name ~ '^[a-z][a-z0-9_]{1,79}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE permissions
            ADD CONSTRAINT permissions_name_format
            CHECK (
                name ~ '^[a-z][a-z0-9_.]{2,119}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE permissions
            ADD CONSTRAINT permissions_action_format
            CHECK (
                action IN (
                    'view',
                    'create',
                    'update',
                    'delete',
                    'manage',
                    'export',
                    'approve',
                    'monitor'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE user_role_assignments
            ADD CONSTRAINT user_role_assignments_valid_dates
            CHECK (
                ends_at IS NULL
                OR ends_at >= starts_at
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};