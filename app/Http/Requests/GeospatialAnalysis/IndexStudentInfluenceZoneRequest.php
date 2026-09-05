<?php

namespace App\Http\Requests\GeospatialAnalysis;

use Illuminate\Foundation\Http\FormRequest;

class IndexStudentInfluenceZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'radius_meters' => [
                'nullable',
                'integer',
                'min:50',
                'max:50000',
            ],

            'max_age_minutes' => [
                'nullable',
                'integer',
                'min:1',
                'max:10080',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'radius_meters.integer' =>
                'El radio de influencia debe expresarse en metros enteros.',

            'radius_meters.min' =>
                'El radio de influencia debe ser de al menos 50 metros.',

            'radius_meters.max' =>
                'El radio de influencia no puede exceder 50000 metros.',

            'max_age_minutes.integer' =>
                'La antigüedad máxima debe expresarse en minutos enteros.',

            'max_age_minutes.min' =>
                'La antigüedad máxima debe ser de al menos 1 minuto.',

            'max_age_minutes.max' =>
                'La antigüedad máxima no puede exceder 10080 minutos.',
        ];
    }
}