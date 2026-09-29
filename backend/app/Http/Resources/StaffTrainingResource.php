<?php

namespace App\Http\Resources;

use App\Models\StaffTraining;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffTraining */
class StaffTrainingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'organizer' => $this->organizer,
            'duration' => $this->duration,
            'year' => $this->year,
            'sort_order' => $this->sort_order,
        ];
    }
}
