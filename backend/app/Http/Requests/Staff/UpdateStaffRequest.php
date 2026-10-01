<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\Staff\Concerns\HasStaffChildRules;
use App\Http\Requests\Staff\Concerns\HasStaffLoginRules;
use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
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
            'name_en' => 'sometimes|nullable|string|max:255',
            'name_bn' => 'sometimes|nullable|string|max:255',
            'employee_id' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('staff', 'employee_id')->ignore($this->route('staff'))],
            'category' => ['sometimes', Rule::in(Staff::CATEGORIES)],
            'position' => ['sometimes', Rule::in(Staff::POSITIONS)],
            'designation' => 'sometimes|nullable|string|max:255',
            'subject' => 'sometimes|nullable|string|max:255',
            'mpo_index' => 'sometimes|nullable|string|max:100',
            'joining_date' => 'sometimes|nullable|date',
            'leaving_date' => 'sometimes|nullable|date',
            'status' => ['sometimes', Rule::in(Staff::STATUSES)],
            'gender' => ['sometimes', 'nullable', Rule::in(Staff::GENDERS)],
            'religion' => 'sometimes|nullable|string|max:100',
            'date_of_birth' => 'sometimes|nullable|date',
            'blood_group' => ['sometimes', 'nullable', Rule::in(Staff::BLOOD_GROUPS)],
            'nationality' => 'sometimes|string|max:100',
            'nid' => 'sometimes|nullable|string|max:50',
            'mobile' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:255',
            'show_contact' => 'sometimes|boolean',
            'present_address' => 'sometimes|nullable|string|max:1000',
            'permanent_address' => 'sometimes|nullable|string|max:1000',
            'district' => 'sometimes|nullable|string|max:100',
            'photo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_photo' => 'sometimes|boolean',
            'bio' => 'sometimes|nullable|string|max:5000',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
            'is_published' => 'sometimes|boolean',
        ], $this->shiftRules(required: false, alwaysAllowedShiftIds: $this->currentShiftIds()), $this->educationRules(), $this->trainingRules(), $this->loginRules());
    }

    /**
     * The ids of every shift this member already has, including an inactive one, so
     * a request that doesn't touch shift_ids at all (or that resubmits the member's
     * current shifts unchanged) never fails because one of them was deactivated after
     * the member was assigned to it.
     *
     * @return list<int>
     */
    private function currentShiftIds(): array
    {
        $staff = $this->route('staff');

        return $staff instanceof Staff ? $staff->shifts()->pluck('shifts.id')->map(fn ($id) => (int) $id)->all() : [];
    }
}
