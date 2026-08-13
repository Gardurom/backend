<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class SecurityRbacSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissions = $this->createPermissions();
            $roles = $this->createRoles();

            $rolePermissions = [
                'super_admin' => [
                    '*',
                ],

                'school_admin' => [
                    'dashboard.view',
                    'students.*',
                    'teachers.*',
                    'groups.*',
                    'subjects.*',
                    'teaching_assignments.*',
                    'assessments.*',
                    'grades.*',
                    'attendance.*',
                    'geodata.*',
                    'geofences.*',
                    'files.*',
                    'users.view',
                    'users.create',
                    'users.update',
                    'audit.view',
                    'audit.export',
                    'campuses.view',
                    'campuses.update',
                ],

                'teacher' => [
                    'dashboard.view',
                    'students.view',
                    'groups.view',
                    'subjects.view',
                    'teaching_assignments.view',
                    'assessments.view',
                    'assessments.create',
                    'assessments.update',
                    'grades.view',
                    'grades.create',
                    'grades.update',
                    'attendance.view',
                    'attendance.create',
                    'attendance.update',
                    'files.view',
                    'files.create',
                ],

                'student' => [
                    'dashboard.view',
                    'grades.view',
                    'attendance.view',
                    'files.view',
                ],

                'guardian' => [
                    'dashboard.view',
                    'grades.view',
                    'attendance.view',
                    'files.view',
                ],

                'geospatial_analyst' => [
                    'dashboard.view',
                    'students.view',
                    'geodata.view',
                    'geodata.export',
                    'geofences.view',
                    'geofences.monitor',
                ],
            ];

            foreach (
                $rolePermissions as $roleName => $patterns
            ) {
                $permissionIds = $patterns === ['*']
                    ? $permissions->pluck('id')
                    : $permissions
                        ->filter(
                            function (
                                Permission $permission
                            ) use ($patterns): bool {
                                foreach (
                                    $patterns as $pattern
                                ) {
                                    if (
                                        $this->matches(
                                            $permission->name,
                                            $pattern
                                        )
                                    ) {
                                        return true;
                                    }
                                }

                                return false;
                            }
                        )
                        ->pluck('id');

                $roles[$roleName]
                    ->permissions()
                    ->sync($permissionIds->all());
            }

            $admin = User::query()
                ->where(
                    'email',
                    'admin@control-escolar.local'
                )
                ->first();

            if ($admin) {
                UserRoleAssignment::updateOrCreate(
                    [
                        'user_id' => $admin->id,
                        'role_id' =>
                            $roles['super_admin']->id,
                        'campus_id' => null,
                    ],
                    [
                        'assigned_by_user_id' => null,
                        'starts_at' => now(),
                        'ends_at' => null,
                        'is_active' => true,
                    ]
                );
            }
        });

        $this->command?->info(
            'Roles y permisos creados correctamente.'
        );
    }

    private function createPermissions(): SupportCollection
    {
        $definitions = [
            'dashboard' => [
                'view',
            ],

            'students' => [
                'view',
                'create',
                'update',
                'delete',
                'export',
            ],

            'teachers' => [
                'view',
                'create',
                'update',
                'delete',
                'export',
            ],

            'groups' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'subjects' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'teaching_assignments' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'assessments' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'grades' => [
                'view',
                'create',
                'update',
                'export',
            ],

            'attendance' => [
                'view',
                'create',
                'update',
                'export',
            ],

            'geodata' => [
                'view',
                'update',
                'export',
            ],

            'geofences' => [
                'view',
                'create',
                'update',
                'delete',
                'manage',
                'monitor',
            ],

            'files' => [
                'view',
                'create',
                'delete',
            ],

            'users' => [
                'view',
                'create',
                'update',
                'delete',
                'manage',
            ],

            'roles' => [
                'view',
                'manage',
            ],

            'audit' => [
                'view',
                'export',
            ],

            'campuses' => [
                'view',
                'create',
                'update',
                'delete',
            ],
        ];

        return collect($definitions)
            ->flatMap(
                function (
                    array $actions,
                    string $module
                ): array {
                    return collect($actions)
                        ->map(
                            function (
                                string $action
                            ) use ($module): Permission {
                                $name =
                                    "{$module}.{$action}";

                                return Permission::updateOrCreate(
                                    [
                                        'name' => $name,
                                    ],
                                    [
                                        'module' => $module,
                                        'action' => $action,
                                        'display_name' =>
                                            ucfirst($module)
                                            .' - '
                                            .ucfirst($action),
                                        'is_active' => true,
                                    ]
                                );
                            }
                        )
                        ->all();
                }
            )
            ->keyBy('name');
    }

    private function createRoles(): SupportCollection
    {
        $definitions = [
            'super_admin' =>
                'Superadministrador',

            'school_admin' =>
                'Administrador escolar',

            'teacher' =>
                'Profesor',

            'student' =>
                'Alumno',

            'guardian' =>
                'Tutor',

            'geospatial_analyst' =>
                'Analista geoespacial',
        ];

        return collect($definitions)
            ->mapWithKeys(
                function (
                    string $displayName,
                    string $name
                ): array {
                    $role = Role::updateOrCreate(
                        [
                            'name' => $name,
                        ],
                        [
                            'display_name' =>
                                $displayName,
                            'is_system' => true,
                            'is_active' => true,
                        ]
                    );

                    return [
                        $name => $role,
                    ];
                }
            );
    }

    private function matches(
        string $permission,
        string $pattern
    ): bool {
        if ($pattern === '*') {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            return str_starts_with(
                $permission,
                substr($pattern, 0, -1)
            );
        }

        return $permission === $pattern;
    }
}