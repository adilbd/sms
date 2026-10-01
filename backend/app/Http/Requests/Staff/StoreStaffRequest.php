<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\Staff\Concerns\HasStaffChildRules;
use App\Http\Requests\Staff\Concerns\HasStaffLoginRules;
use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    use HasStaffChildRules, HasStaffLoginRules;

    // Access is enforced by the permission middleware in StaffController.
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeLoginEmail();
    }

    public function rules(): array
    {
        return array_merge([
            // At least one of name_en/name_bn is required, but that's checked in
            // StaffService against the model with the input applied (see
            // SubjectService::ensurePassMarksWithinTotal() for the same pattern), not
            // here, so the same rule also protects a partial update.
            'name_en' => 'nullable|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'employee_id' => ['nullable', 'string', 'max:255', Rule::unique('staff', 'employee_id')],
            'category' => ['required', Rule::in(Staff::CATEGORIES)],
            'position' => ['required', Rule::in(Staff::POSITIONS)],
            'designation' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:255',
            'mpo_index' => 'nullable|string|max:100',
            'joining_date' => 'nullable|date',
            'leaving_date' => 'nullable|date',
            'status' => ['sometimes', Rule::in(Staff::STATUSES)],
            'gender' => ['nullable', Rule::in(Staff::GENDERS)],
            'religion' => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date',
            'blood_group' => ['nullable', Rule::in(Staff::BLOOD_GROUPS)],
            'nationality' => 'sometimes|string|max:100',
            'nid' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'show_contact' => 'sometimes|boolean',
            'present_address' => 'nullable|string|max:1000',
            'permanent_address' => 'nullable|string|max:1000',
            'district' => 'nullable|string|max:100',
            // SVG is excluded: it can carry script content (same as post-body uploads).
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'bio' => 'nullable|string|max:5000',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
            'is_published' => 'sometimes|boolean',
        ], $this->shiftRules(required: true), $this->educationRules(), $this->trainingRules(), $this->loginRules());
    }
}
