<?php

namespace App\Http\Middleware;

use App\Models\Campus;
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

        if (
            ! $this->authorization->userHasPermission(
                $user,
                $permission,
                $campusId
            )
        ) {
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

        if (is_string($routeCampus) && Str::isUuid($routeCampus)) {
            return $routeCampus;
        }

        $routeCampusId = $request->route('campus_id');

        if (
            is_string($routeCampusId)
            && Str::isUuid($routeCampusId)
        ) {
            return $routeCampusId;
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