<?php

namespace App\Http\Requests\AdmissionApplication;

use App\Models\AdmissionApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Whether the move is allowed depends on the application's current status and the seats,
 * so AdmissionApplicationService checks that. `test_at` is an ISO 8601 timestamp (the
 * admin SPA sends the Dhaka time with its +06:00 offset); it is stored in UTC.
 */
class UpdateAdmissionApplicationStatusRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AdmissionApplicationController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(AdmissionApplication::SETTABLE_STATUSES)],
            'test_at' => ['nullable', 'required_if:status,'.AdmissionApplication::STATUS_TEST_SCHEDULED, 'date'],
            'test_venue' => 'nullable|string|max:255',
            'test_score' => 'nullable|numeric|min:0|max:9999.99',
            'admin_note' => 'nullable|string|max:2000',
        ];
    }
}
