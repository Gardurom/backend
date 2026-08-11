<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRoleAssignment;

class AuthorizationService
{
    public function userHasPermission(
        User $user,
        string $permission,
        ?string $campusId = null,
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        return UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(function ($query): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->when(
                $campusId === null,
                function ($query) {
                    return $query->whereNull('campus_id');
                },
                function ($query) use ($campusId) {
                    return $query->where(
                        function ($campusQuery) use (
                            $campusId
                        ): void {
                            $campusQuery
                                ->whereNull('campus_id')
                                ->orWhere(
                                    'campus_id',
                                    $campusId
                                );
                        }
                    );
                }
            )
            ->whereHas(
                'role',
                function ($roleQuery) use ($permission): void {
                    $roleQuery
                        ->where('is_active', true)
                        ->whereHas(
                            'permissions',
                            function (
                                $permissionQuery
                            ) use ($permission): void {
                                $permissionQuery
                                    ->where(
                                        'permissions.name',
                                        $permission
                                    )
                                    ->where(
                                        'permissions.is_active',
                                        true
                                    );
                            }
                        );
                }
            )
            ->exists();
    }
}