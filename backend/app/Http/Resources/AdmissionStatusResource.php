<?php

namespace App\Http\Resources;

use App\Models\AdmissionApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdmissionApplication
 *
 * What a family sees after the status lookup: the applicant's name, class, status and the
 * test details. Never the admin note, the score, contact details, addresses or the birth
 * registration number. The website's status page shows the same fields.
 */
class AdmissionStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'application_no' => $this->application_no,
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            'class' => $this->whenLoaded('class', fn () => [
                'id' => $this->class->id,
                'number' => $this->class->number,
                'name' => $this->class->name,
                'name_bn' => $this->class->name_bn,
            ]),
            'round' => $this->whenLoaded('round', fn () => [
                'name_en' => $this->round->name_en,
                'name_bn' => $this->round->name_bn,
            ]),
            'status' => $this->status,
            'status_label_bn' => AdmissionApplication::STATUS_LABELS_BN[$this->status] ?? $this->status,
            'status_label_en' => AdmissionApplication::STATUS_LABELS_EN[$this->status] ?? $this->status,
            'test_at' => $this->test_at?->toIso8601String(),
            'test_venue' => $this->test_venue,
            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
