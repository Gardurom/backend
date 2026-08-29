<?php

namespace App\Http\Requests\StudentPosition;

use Illuminate\Foundation\Http\FormRequest;

class IndexStudentPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => [
                'nullable',
                'date',
            ],

            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
            ],

            'source' => [
                'nullable',
                'string',
                'in:gps,mobile,manual,import,device',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'from.date' => 'La fecha inicial no tiene un formato válido.',

            'to.date' => 'La fecha final no tiene un formato válido.',
            'to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',

            'source.string' => 'El origen de la posición debe ser una cadena de texto.',
            'source.in' => 'El origen de la posición no es válido.',

            'limit.integer' => 'El límite debe ser un número entero.',
            'limit.min' => 'El límite debe ser al menos 1.',
            'limit.max' => 'El límite no puede ser mayor a 500.',
        ];
    }
}