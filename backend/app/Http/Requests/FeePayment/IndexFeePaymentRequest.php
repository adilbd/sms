<?php

namespace App\Http\Requests\FeePayment;

use App\Models\FeePayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexFeePaymentRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeePaymentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'sometimes|nullable|integer|min:1',
            'method' => ['sometimes', 'nullable', 'string', Rule::in(FeePayment::METHODS)],
            'collected_by' => 'sometimes|nullable|integer|min:1',
            // Asia/Dhaka dates, inclusive.
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:from',
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'cancelled'])],
            'search' => 'sometimes|nullable|string|max:100',
            'per_page' => 'sometimes|nullable|integer|min:1|max:100',
        ];
    }
}
