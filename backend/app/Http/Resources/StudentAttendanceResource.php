<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One student's attendance month plus their year-to-date totals
 * (AttendanceService::studentMonth()/ownMonth()/childMonth()). Used by the staff endpoint
 * and by `/api/my/*`, so they always agree.
 *
 * @property array<string, mixed> $resource
 */
class StudentAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource['month'],
            'student' => $this->resource['student'],
            'school_days' => $this->resource['school_days'],
            'days' => (object) $this->resource['days'],
            'totals' => $this->resource['totals'],
            'percentage' => $this->resource['percentage'],
            'year_to_date' => $this->resource['year_to_date'],
        ];
    }
}
