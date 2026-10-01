<?php

namespace App\Http\Requests\FeeReport;

use App\Models\FeePayment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CollectionReportRequest extends FormRequest
{
    // The longest range one report covers, so the receipt list stays a sane size.
    private const MAX_DAYS = 366;

    // Access is enforced by the permission middleware in FeeReportController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Asia/Dhaka dates, inclusive.
            'from' => 'required|date_format:Y-m-d',
            'to' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:from',
                function (string $attribute, mixed $value, Closure $fail) {
                    $from = $this->input('from');

                    if (is_string($from) && is_string($value)
                        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                        && Carbon::parse($from)->diffInDays(Carbon::parse($value)) >= self::MAX_DAYS) {
                        $fail('The range can be at most a year.');
                    }
                },
            ],
            'method' => ['sometimes', 'nullable', 'string', Rule::in(FeePayment::METHODS)],
            'collected_by' => 'sometimes|nullable|integer|exists:users,id',
        ];
    }
}
