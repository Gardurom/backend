<?php

namespace App\Http\Requests\GeospatialAnalysis;

use Illuminate\Foundation\Http\FormRequest;

class IndexStudentHeatmapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}