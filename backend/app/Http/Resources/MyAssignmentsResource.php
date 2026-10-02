<?php

namespace App\Http\Resources;

use App\Services\TeacherContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeacherContext|null
 *
 * A signed-in teacher's own work for one academic year: the subjects they teach (each with
 * its section and class) and every section they lead as class teacher (main or co-teacher, with `is_main`). A teacher with no
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
                ? $context->classSections->map(fn ($row) => [
                    ...(new SectionResource($row->section))->resolve(),
                    'is_main' => (bool) $row->is_main,
                ])->values()->all()
                : [],
        ];
    }
}
