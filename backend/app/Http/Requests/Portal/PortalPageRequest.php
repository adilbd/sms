<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional query of every portal page: `?student=` (a guardian's child switcher; a
 * student's own is ignored) and `?month=YYYY-MM` (attendance).
 */
class PortalPageRequest extends FormRequest
{
    // The `portal` middleware group already requires a signed-in student or guardian; whose
    // record may be read is checked in PortalService (StudentService::findChildOf()).
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            // Print options (`language`, `orientation`, the paper `page`) live in PortalPrintRequest,
            // so a plain page never rejects or narrows a `?page=` it doesn't use.
        ];
    }

    /** The requested child id, or null. */
    public function studentId(): ?int
    {
        return filled($this->validated('student')) ? (int) $this->validated('student') : null;
    }
}
