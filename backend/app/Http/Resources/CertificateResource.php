<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Certificate
 *
 * The register shape. The snapshot (`snapshot`, the `data` column; date of birth, parents' names, ...) is opt-in
 * like PostResource::withBody(): lists omit it, show/store/cancel send it. The student is
 * a narrow shape.
 */
class CertificateResource extends JsonResource
{
    public bool $withData = false;

    public function withData(bool $withData = true): static
    {
        $this->withData = $withData;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'serial_no' => $this->serial_no,
            'status' => $this->isCancelled() ? Certificate::STATUS_CANCELLED : Certificate::STATUS_ISSUED,
            'student_id' => $this->student_id,
            'enrolment_id' => $this->enrolment_id,
            'academic_year_id' => $this->academic_year_id,
            'issued_on' => $this->issued_on?->toDateString(),
            'issued_by' => $this->issued_by,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by,
            'cancel_reason' => $this->cancel_reason,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_id' => $this->student->student_id,
                'name_en' => $this->student->name_en,
                'name_bn' => $this->student->name_bn,
            ]),
            'issuer' => $this->whenLoaded('issuer', fn () => $this->issuer ? ['id' => $this->issuer->id, 'name' => $this->issuer->name] : null),
            'canceller' => $this->whenLoaded('canceller', fn () => $this->canceller ? ['id' => $this->canceller->id, 'name' => $this->canceller->name] : null),
            // Not called `data`: a `data` key would stop Laravel wrapping the resource in `data`.
            'snapshot' => $this->when($this->withData, $this->data),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
