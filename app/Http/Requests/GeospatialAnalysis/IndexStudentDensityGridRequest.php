<?php

namespace App\Http\Requests\GeospatialAnalysis;

use Illuminate\Foundation\Http\FormRequest;

class IndexStudentDensityGridRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cell_size_meters' => [
                'nullable',
                'integer',
                'min:50',
                'max:10000',
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
            'cell_size_meters.integer' =>
                'El tamaño de celda debe expresarse en metros enteros.',

            'cell_size_meters.min' =>
                'El tamaño de celda debe ser de al menos 50 metros.',

            'cell_size_meters.max' =>
                'El tamaño de celda no puede exceder 10000 metros.',

            'max_age_minutes.integer' =>
                'La antigüedad máxima debe expresarse en minutos enteros.',

            'max_age_minutes.min' =>
                'La antigüedad máxima debe ser de al menos 1 minuto.',

            'max_age_minutes.max' =>
                'La antigüedad máxima no puede exceder 10080 minutos.',
        ];
    }
}