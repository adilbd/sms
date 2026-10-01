<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the array PromotionService::apply() returns. */
class PromotionResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'summary' => $this->resource['summary'],
            'target_sections' => $this->resource['target_sections'],
        ];
    }
}
