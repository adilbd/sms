<?php

namespace App\Http\Requests\FeePayment;

use App\Models\FeePayment;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeePaymentRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeePaymentController.
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Transaction IDs are compared case-insensitively and without padding, so they are
     * stored trimmed and uppercase (FeePaymentService does the same).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('transaction_id'))) {
            $this->merge(['transaction_id' => strtoupper(trim($this->input('transaction_id')))]);
        }
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            // Whether it is within the outstanding amount is checked in FeePaymentService.
            'amount' => 'required|numeric|decimal:0,2|regex:'.Money::MONEY_PATTERN.'|min:0.01|max:99999999.99',
            'method' => ['required', 'string', Rule::in(FeePayment::METHODS)],
            // Required for bKash, Nagad and Rocket (FeePaymentService); ignored for cash.
            'transaction_id' => 'nullable|string|max:64',
            // An ISO 8601 time with its offset (e.g. +06:00). Only an admin may send it
            // (FeePaymentService), to backdate an entry.
            'paid_at' => 'nullable|date|before_or_equal:now',
            'note' => 'nullable|string|max:500',
            'due_ids' => 'nullable|array|max:200',
            'due_ids.*' => 'integer|min:1|distinct',
        ];
    }
}
