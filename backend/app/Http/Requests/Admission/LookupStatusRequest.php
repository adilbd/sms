<?php

namespace App\Http\Requests\Admission;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * The public status lookup (website form and /api/public/admission-status): the
 * application number and the child's date of birth. Both must match a stored application
 * (AdmissionService::lookupStatus()).
 */
class LookupStatusRequest extends FormRequest
{
    // Public by design: the date of birth, the throttle and the identical "not found"
    // answer protect it, not a login.
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('application_no'))) {
            $this->merge(['application_no' => mb_strtoupper(preg_replace('/\s+/', '', $this->input('application_no')))]);
        }
    }

    /**
     * The website form redirects back with the errors, but never flashes the child's date
     * of birth into the session. JSON clients keep the normal 422.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new ValidationException(
            $validator,
            redirect($this->getRedirectUrl())
                ->withInput($this->except('date_of_birth'))
                ->withErrors($validator->errors(), $this->errorBag),
        );
    }

    public function rules(): array
    {
        return [
            'application_no' => ['required', 'string', 'max:30'],
            'date_of_birth' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
