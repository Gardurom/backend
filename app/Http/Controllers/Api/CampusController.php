<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campus\StoreCampusRequest;
use App\Http\Requests\Campus\UpdateCampusRequest;
use App\Http\Resources\CampusResource;
use App\Models\Campus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CampusController extends Controller
{
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $campuses = Campus::query()
            ->when(
                $validated['search'] ?? null,
                function ($query, string $search): void {
                    $query->where(function ($searchQuery) use (
                        $search
                    ): void {
                        $searchQuery
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('code', 'ilike', "%{$search}%")
                            ->orWhere(
                                'official_key',
                                'ilike',
                                "%{$search}%"
                            );
                    });
                }
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where(
                    'is_active',
                    $validated['is_active']
                )
            )
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return CampusResource::collection($campuses);
    }

    public function store(
        StoreCampusRequest $request
    ): CampusResource {
        $campus = Campus::create($request->validated());

        return new CampusResource($campus);
    }

    public function show(Campus $campus): CampusResource
    {
        return new CampusResource($campus);
    }

    public function update(
        UpdateCampusRequest $request,
        Campus $campus,
    ): CampusResource {
        $campus->update($request->validated());

        return new CampusResource($campus->refresh());
    }

    public function destroy(Campus $campus): Response
    {
        $hasDependencies =
            $campus->schoolCycles()->exists()
            || $campus->students()->exists()
            || $campus->teachers()->exists();

        abort_if(
            $hasDependencies,
            409,
            'No se puede eliminar un plantel con datos relacionados.'
        );

        $campus->delete();

        return response()->noContent();
    }
}