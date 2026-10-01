<?php

namespace App\Http\Requests\FeePayment;

use Illuminate\Foundation\Http\FormRequest;

class CancelFeePaymentRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeePaymentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:500',
        ];
    }
}
