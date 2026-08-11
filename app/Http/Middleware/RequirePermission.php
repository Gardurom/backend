<?php

namespace App\Http\Middleware;

use App\Models\Campus;
use App\Models\Geofence;
use App\Models\SchoolGroup;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function handle(
        Request $request,
        Closure $next,
        string $permission,
    ): Response {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse([
                'message' => 'No autenticado.',
            ], 401);
        }

        $campusId = $this->resolveCampusId($request);

        $hasPermission = $this->authorization->userHasPermission(
            user: $user,
            permission: $permission,
            campusId: $campusId,
        );

        if (! $hasPermission) {
            return new JsonResponse([
                'message' => 'No tienes permiso para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }

    private function resolveCampusId(Request $request): ?string
    {
        $routeCampus = $request->route('campus');

        if ($routeCampus instanceof Campus) {
            return $routeCampus->id;
        }

        if (
            is_string($routeCampus)
            && Str::isUuid($routeCampus)
        ) {
            return $routeCampus;
        }

        $routeModels = [
            $request->route('student'),
            $request->route('teacher'),
            $request->route('subject'),
            $request->route('geofence'),
        ];

        foreach ($routeModels as $model) {
            if (
                $model instanceof Student
                || $model instanceof Teacher
                || $model instanceof Subject
                || $model instanceof Geofence
            ) {
                return $model->campus_id;
            }
        }

        $routeGroup = $request->route('schoolGroup');

        if ($routeGroup instanceof SchoolGroup) {
            return $routeGroup
                ->schoolCycle()
                ->value('campus_id');
        }

        $routeCampusId = $request->route('campus_id');

        if (
            is_string($routeCampusId)
            && Str::isUuid($routeCampusId)
        ) {
            return $routeCampusId;
        }

        $attributeCampusId = $request->attributes->get('campus_id');

        if (
            is_string($attributeCampusId)
            && Str::isUuid($attributeCampusId)
        ) {
            return $attributeCampusId;
        }

        $headerCampusId = $request->header('X-Campus-ID');

        if (
            is_string($headerCampusId)
            && Str::isUuid($headerCampusId)
        ) {
            return $headerCampusId;
        }

        return null;
    }
}