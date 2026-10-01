<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

/**
 * What one teacher teaches and leads in one academic year: the staff row, the year, the
 * subject assignments (SubjectAssignment, with subject/class/section loaded) and the
 * class-teacher rows (ClassSection, with section loaded). Built by TeacherScope.
 */
final class TeacherContext
{
    public function __construct(
        public readonly Staff $staff,
        public readonly ?AcademicYear $year,
        public readonly Collection $assignments,
        public readonly Collection $classSections,
    ) {}

    /**
     * @return list<int>
     */
    public function teachingSectionIds(): array
    {
        return $this->assignments->pluck('section_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * @return list<int>
     */
    public function leadingSectionIds(): array
    {
        return $this->classSections->pluck('section_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Every section the teacher teaches in or leads.
     *
     * @return list<int>
     */
    public function sectionIds(): array
    {
        return array_values(array_unique([...$this->teachingSectionIds(), ...$this->leadingSectionIds()]));
    }

    /**
     * @return list<array{class_id: int, subject_id: int}>
     */
    public function classSubjectPairs(): array
    {
        return $this->assignments
            ->map(fn ($a) => ['class_id' => (int) $a->class_id, 'subject_id' => (int) $a->subject_id])
            ->unique(fn (array $pair) => $pair['class_id'].'-'.$pair['subject_id'])
            ->values()
            ->all();
    }
}
