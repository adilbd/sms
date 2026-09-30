<?php

namespace App\Http\Resources;

use App\Models\StaffEducation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffEducation */
class StaffEducationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'degree' => $this->degree,
            'institution' => $this->institution,
            'board_university' => $this->board_university,
            'passing_year' => $this->passing_year,
            'result' => $this->result,
            'sort_order' => $this->sort_order,
        ];
    }
}
