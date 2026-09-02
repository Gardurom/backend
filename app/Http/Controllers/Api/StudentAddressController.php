<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentAddress\StoreStudentAddressRequest;
use App\Http\Requests\StudentAddress\UpdateStudentAddressRequest;
use App\Http\Resources\StudentAddressResource;
use App\Models\Student;
use App\Models\StudentAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class StudentAddressController extends Controller
{
    public function index(
        Request $request,
        Student $student,
    ): JsonResponse {
        $this->ensureStudentBelongsToCampus(
            $request,
            $student,
        );

        $addresses = StudentAddress::query()
            ->where(
                'student_id',
                $student->getKey(),
            )
            ->select('student_addresses.*')
            ->selectRaw(
                'ST_AsGeoJSON(location::geometry) AS location_geojson'
            )
            ->orderByDesc('is_primary')
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->get();

        $resource = StudentAddressResource::collection(
            $addresses
        );

        return response()->json([
            'data' => $resource->resolve($request),
            'meta' => [
                'count' => $addresses->count(),
            ],
        ]);
    }

    public function store(
        StoreStudentAddressRequest $request,
        Student $student,
    ): JsonResponse {
        $this->ensureStudentBelongsToCampus(
            $request,
            $student,
        );

        $validated = $request->validated();

        $address = DB::transaction(
            function () use (
                $student,
                $validated,
            ): StudentAddress {
                $hasActiveAddresses = StudentAddress::query()
                    ->where(
                        'student_id',
                        $student->getKey(),
                    )
                    ->exists();

                $isPrimary = array_key_exists(
                    'is_primary',
                    $validated,
                )
                    ? (bool) $validated['is_primary']
                    : ! $hasActiveAddresses;

                if ($isPrimary) {
                    $this->demoteCurrentPrimary(
                        $student->getKey()
                    );
                }

                $attributes = $validated;

                unset(
                    $attributes['location'],
                    $attributes['is_primary'],
                );

                $attributes['student_id'] =
                    $student->getKey();

                $attributes['is_primary'] =
                    $isPrimary;

                $address = StudentAddress::query()->create(
                    $attributes
                );

                $locationChanged = $this->applyLocation(
                    $address,
                    $validated,
                );

                if ($locationChanged) {
                    $address->touch();
                }

                return $address;
            },
            attempts: 3,
        );

        $address = $this->findAddressWithSpatialData(
            $address->getKey()
        );

        return response()->json([
            'data' => (
                new StudentAddressResource($address)
            )->resolve($request),
        ], 201);
    }

    public function show(
        Request $request,
        Student $student,
        StudentAddress $address,
    ): JsonResponse {
        $this->ensureStudentBelongsToCampus(
            $request,
            $student,
        );

        $this->ensureAddressBelongsToStudent(
            $student,
            $address,
        );

        $address = $this->findAddressWithSpatialData(
            $address->getKey()
        );

        return response()->json([
            'data' => (
                new StudentAddressResource($address)
            )->resolve($request),
        ]);
    }

    public function update(
        UpdateStudentAddressRequest $request,
        Student $student,
        StudentAddress $address,
    ): JsonResponse {
        $this->ensureStudentBelongsToCampus(
            $request,
            $student,
        );

        $this->ensureAddressBelongsToStudent(
            $student,
            $address,
        );

        $validated = $request->validated();

        DB::transaction(
            function () use (
                $student,
                $address,
                $validated,
            ): void {
                if (
                    array_key_exists(
                        'is_primary',
                        $validated,
                    )
                    && (bool) $validated['is_primary']
                ) {
                    $this->demoteCurrentPrimary(
                        $student->getKey(),
                        $address->getKey(),
                    );
                }

                $attributes = $validated;

                unset(
                    $attributes['location'],
                );

                if ($attributes !== []) {
                    $address->fill($attributes);
                    $address->save();
                }

                $locationChanged = $this->applyLocation(
                    $address,
                    $validated,
                );

                if ($locationChanged) {
                    $address->touch();
                }
            },
            attempts: 3,
        );

        $address = $this->findAddressWithSpatialData(
            $address->getKey()
        );

        return response()->json([
            'data' => (
                new StudentAddressResource($address)
            )->resolve($request),
        ]);
    }

    public function destroy(
        Request $request,
        Student $student,
        StudentAddress $address,
    ): JsonResponse {
        $this->ensureStudentBelongsToCampus(
            $request,
            $student,
        );

        $this->ensureAddressBelongsToStudent(
            $student,
            $address,
        );

        $address->delete();

        return response()->json(
            null,
            204
        );
    }

    private function ensureStudentBelongsToCampus(
        Request $request,
        Student $student,
    ): void {
        $campusId = (string) $request->attributes->get(
            'campus_id'
        );

        if ($campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' => [
                    'No se proporciono un contexto de plantel valido.',
                ],
            ]);
        }

        if ($student->campus_id !== $campusId) {
            abort(404);
        }
    }

    private function ensureAddressBelongsToStudent(
        Student $student,
        StudentAddress $address,
    ): void {
        if (
            $address->student_id
            !== $student->getKey()
        ) {
            abort(404);
        }
    }

    private function demoteCurrentPrimary(
        string $studentId,
        ?string $exceptAddressId = null,
    ): void {
        $query = StudentAddress::query()
            ->where(
                'student_id',
                $studentId,
            )
            ->where(
                'is_primary',
                true,
            );

        if ($exceptAddressId !== null) {
            $query->where(
                'id',
                '<>',
                $exceptAddressId,
            );
        }

        $query->update([
            'is_primary' => false,
            'updated_at' => now(),
        ]);
    }

    private function findAddressWithSpatialData(
        string $addressId,
    ): StudentAddress {
        return StudentAddress::query()
            ->select('student_addresses.*')
            ->selectRaw(
                'ST_AsGeoJSON(location::geometry) AS location_geojson'
            )
            ->findOrFail($addressId);
    }

    private function applyLocation(
        StudentAddress $address,
        array $validated,
    ): bool {
        if (! array_key_exists('location', $validated)) {
            return false;
        }

        if ($validated['location'] === null) {
            DB::table('student_addresses')
                ->where(
                    'id',
                    $address->getKey(),
                )
                ->update([
                    'location' => null,
                ]);

            return true;
        }

        $geoJson = $this->encodeGeoJson(
            $validated['location']
        );

        DB::update(
            <<<'SQL'
            UPDATE student_addresses
            SET location = (
                ST_SetSRID(
                    ST_GeomFromGeoJSON(?),
                    4326
                )
            )::geography
            WHERE id = ?
            SQL,
            [
                $geoJson,
                $address->getKey(),
            ],
        );

        return true;
    }

    private function encodeGeoJson(
        array $location,
    ): string {
        try {
            return json_encode(
                $location,
                JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw ValidationException::withMessages([
                'location' => [
                    'La ubicacion no puede convertirse a GeoJSON valido.',
                ],
            ]);
        }
    }
}