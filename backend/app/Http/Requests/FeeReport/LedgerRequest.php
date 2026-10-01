<?php

namespace App\Http\Requests\FeeReport;

use Illuminate\Foundation\Http\FormRequest;

class LedgerRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeReportController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,id',
        ];
    }
}
