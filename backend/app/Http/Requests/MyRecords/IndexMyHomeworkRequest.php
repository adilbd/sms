<?php

namespace App\Http\Requests\MyRecords;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `?student=` picks a guardian's child (ignored for a student); `from`/`to` bound the due
 * date and `due` is `upcoming` (due today or later) or `past`.
 */
class IndexMyHomeworkRequest extends FormRequest
{
    // Access is enforced by the role middleware on the route; whose record may be read is
    // checked in PortalService::resolveStudent().
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'due' => ['sometimes', 'nullable', Rule::in(['upcoming', 'past'])],
        ];
    }

    public function studentId(): ?int
    {
        return filled($this->validated('student')) ? (int) $this->validated('student') : null;
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter($this->safe()->only(['from', 'to', 'due']), fn ($v) => filled($v));
    }
}
