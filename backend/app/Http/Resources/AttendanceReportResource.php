<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A section's monthly attendance grid (AttendanceService::report()): the school days of
 * the month so far and, per student, the status of each day, the totals and the
 * percentage (a `decimal:2` string).
 *
 * @property array{month: string, section: \App\Models\Section, school_days: list<string>, students: list<array<string, mixed>>} $resource
 */
class AttendanceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource['month'],
            'section_id' => $this->resource['section']->id,
            'school_days' => $this->resource['school_days'],
            'students' => array_map(fn (array $row) => [
                'student' => $row['student'],
                // An empty list would encode as [] instead of an object.
                'days' => (object) $row['days'],
                'totals' => $row['totals'],
                'percentage' => $row['percentage'],
            ], $this->resource['students']),
        ];
    }
}
