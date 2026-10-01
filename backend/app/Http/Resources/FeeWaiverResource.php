<?php

namespace App\Http\Resources;

use App\Models\StudentFeeWaiver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StudentFeeWaiver */
class FeeWaiverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'academic_year_id' => $this->academic_year_id,
            'fee_head_id' => $this->fee_head_id,
            'percent' => $this->percent,
            'fixed_amount' => $this->fixed_amount,
            'reason' => $this->reason,
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'head' => new FeeHeadResource($this->whenLoaded('head')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
