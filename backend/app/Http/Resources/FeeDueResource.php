<?php

namespace App\Http\Resources;

use App\Models\FeeDue;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeeDue */
class FeeDueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'enrolment_id' => $this->enrolment_id,
            'fee_head_id' => $this->fee_head_id,
            'period' => $this->period,
            'amount' => $this->amount,
            'waiver_amount' => $this->waiver_amount,
            'net_amount' => $this->net_amount,
            'paid_amount' => $this->paid_amount,
            'outstanding_amount' => Money::fromPaisa($this->outstandingPaisa()),
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            'head' => new FeeHeadResource($this->whenLoaded('head')),
            'student' => new FeeStudentResource($this->whenLoaded('student')),
            'enrolment' => new StudentEnrolmentResource($this->whenLoaded('enrolment')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
