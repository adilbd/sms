<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The few student fields the fee screens and receipts show. No contact details, dates of
 * birth or addresses: those stay in StudentResource, behind the students permissions.
 *
 * @mixin Student
 */
class FeeStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
        ];
    }
}
