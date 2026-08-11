<?php

namespace App\Http\Middleware;

use App\Models\Campus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireCampusContext
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $campusId = $request->header('X-Campus-ID');

        if (! is_string($campusId) || ! Str::isUuid($campusId)) {
            return new JsonResponse([
                'message' => 'El encabezado X-Campus-ID es obligatorio.',
                'errors' => [
                    'campus_id' => [
                        'Debe proporcionar un UUID de plantel válido.',
                    ],
                ],
            ], 422);
        }

        $campus = Campus::query()
            ->whereKey($campusId)
            ->where('is_active', true)
            ->first();

        if (! $campus) {
            return new JsonResponse([
                'message' => 'El plantel indicado no existe o está inactivo.',
            ], 404);
        }

        $request->attributes->set('campus', $campus);
        $request->attributes->set('campus_id', $campus->id);

        return $next($request);
    }
}