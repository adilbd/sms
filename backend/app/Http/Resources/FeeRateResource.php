<?php

namespace App\Http\Resources;

use App\Models\FeeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeeRate */
class FeeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fee_head_id' => $this->fee_head_id,
            'class_id' => $this->class_id,
            'academic_year_id' => $this->academic_year_id,
            'group' => $this->group,
            'amount' => $this->amount,
            'due_day' => $this->due_day,
            'head' => new FeeHeadResource($this->whenLoaded('head')),
            'class' => new ClassResource($this->whenLoaded('class')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
