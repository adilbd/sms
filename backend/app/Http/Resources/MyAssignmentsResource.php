<?php

namespace App\Http\Resources;

use App\Services\TeacherContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeacherContext|null
 *
 * A signed-in teacher's own work for one academic year: the subjects they teach (each with
 * its section and class) and the sections they lead as class teacher. A teacher with no
 * staff link (null resource) gets an empty block. Expects the context's relations loaded
 * by TeacherScope::forUser().
 */
class MyAssignmentsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TeacherContext|null $context */
        $context = $this->resource;

        return [
            'academic_year' => $context?->year ? new AcademicYearResource($context->year) : null,
            'subjects' => $context
                ? $context->assignments->map(fn ($assignment) => [
                    'section' => new SectionResource($assignment->section),
                    'subject' => new SubjectResource($assignment->subject),
                    'class' => new ClassResource($assignment->class),
                ])->values()->all()
                : [],
            'class_teacher_of' => $context
                ? SectionResource::collection($context->classSections->map(fn ($row) => $row->section)->values())->resolve()
                : [],
        ];
    }
}
